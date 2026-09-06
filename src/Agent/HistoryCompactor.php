<?php

declare(strict_types=1);

namespace App\Agent;

use App\Memory\ContextStore;
use App\Platform\PlatformInterface;
use App\Runtime\Interrupt;

/**
 * Keeps the conversation inside the context window, two ways: elide old tool
 * output (free, but the model re-reads the file if it needs it again) or
 * summarise the oldest turns through the model (a forward pass, keeping the
 * conclusions). Which comes first is InferenceSpeed against a wall-clock
 * budget — a forward pass is a second on a GPU, minutes on a CPU.
 *
 * Either way compaction aims at ContextBudget::compactTarget(), not just under
 * the trigger: rewriting history invalidates the server's prefix cache, so
 * compact rarely and deeply. The system message is never touched.
 */
final class HistoryCompactor
{
    /** Recent messages left completely intact — the model is still working with these. */
    private const KEEP_RECENT = 6;

    /** Tool outputs longer than this become a stub when they age out. */
    private const ELIDE_OVER_CHARS = 600;

    /** Exchanges kept verbatim after a summarisation. */
    private const KEEP_AFTER_SUMMARY = 6;

    /**
     * How long a summarising pass may take before it stops being the better
     * option. Wall clock, because what matters is how long someone sits in
     * front of a silent terminal: 20 s is a 30B model reading a full window on
     * a mid-range card, and a fraction of the same pass on a laptop CPU.
     */
    private const SUMMARY_BUDGET_SECONDS = 20.0;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly ContextBudget $budget,
        // Optional only because they follow a defaulted signature elsewhere in
        // the codebase; services.yaml autowires both and context_test builds a
        // compactor without them to assert the unmeasured path still works.
        private readonly ?InferenceSpeed $speed = null,
        private readonly ?Interrupt $interrupt = null,
        // Where an elided body goes instead of nowhere. Optional: without it
        // elision is the straight loss it always was, which is what the older
        // tests construct and assert.
        private readonly ?ContextStore $context = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>> $toolSchemas
     * @return string[] human-readable description of what was done
     */
    public function compact(MessageBag $bag, array $toolSchemas = []): array
    {
        $actions = [];
        $summarised = false;

        if ($this->summaryIsAffordable($bag)) {
            $summarised = $this->summariseOldTurns($bag);
            if ($summarised) {
                $actions[] = 'history summarised';
            }
        }

        // Skipped entirely when the summary already got us under target, which
        // is the point of doing it first: recent tool output survives intact.
        if ($this->budget->isAboveTarget($bag->all(), $toolSchemas)) {
            $elided = $this->elideOldToolOutputs($bag);
            if ($elided > 0) {
                $actions[] = "{$elided} tool output(s) elided";
            }
        }

        if (!$summarised
            && $this->budget->isAboveTarget($bag->all(), $toolSchemas)
            && $this->summariseOldTurns($bag)
        ) {
            $actions[] = 'history summarised';
        }

        return $actions;
    }

    /**
     * Whether summarising is worth doing before eliding. Unmeasured means the
     * frugal answer: guessing "fast" costs a minute of somebody's CPU to find
     * out it was wrong.
     */
    private function summaryIsAffordable(MessageBag $bag): bool
    {
        if ($this->speed === null || !$this->speed->isMeasured()) {
            return false;
        }

        $seconds = $this->speed->secondsToPrefill($this->budget->estimateMessages($bag->all()));

        return $seconds !== null && $seconds <= self::SUMMARY_BUDGET_SECONDS;
    }

    /**
     * Move the body of aged-out tool results out of the window. It is handed to
     * ContextStore on the way, and the stub carries the id that brings it back:
     * the same saving, without the loss.
     */
    private function elideOldToolOutputs(MessageBag $bag): int
    {
        $messages = $bag->all();
        $cutoff = count($messages) - self::KEEP_RECENT;
        $elided = 0;

        foreach ($messages as $i => $message) {
            if ($i >= $cutoff) {
                break;
            }
            if (($message['role'] ?? '') !== 'tool') {
                continue;
            }

            $content = (string) ($message['content'] ?? '');
            if (mb_strlen($content) <= self::ELIDE_OVER_CHARS || str_starts_with($content, '[…')) {
                continue;
            }

            $lines = substr_count($content, "\n") + 1;
            $name = (string) ($message['name'] ?? 'tool');

            $id = $this->context?->keep($name, $content);

            $messages[$i]['content'] = $id === null
                ? sprintf(
                    '[… %s output elided to save context: %d lines, %d characters. '
                    . 'Call the tool again if you need the detail.]',
                    $name,
                    $lines,
                    mb_strlen($content),
                )
                : sprintf(
                    '[… %s output elided to save context: %d lines, %d characters. '
                    . 'Still readable without calling the tool again: context_recall(search: "…") '
                    . 'or context_recall(id: %d).]',
                    $name,
                    $lines,
                    mb_strlen($content),
                    $id,
                );
            $elided++;
        }

        if ($elided > 0) {
            $bag->replace($messages);
        }

        return $elided;
    }

    /**
     * Fold everything but the most recent exchanges into one summary message.
     */
    private function summariseOldTurns(MessageBag $bag): bool
    {
        $messages = $bag->all();

        $system = [];
        $rest = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                $system[] = $message;
            } else {
                $rest[] = $message;
            }
        }

        if (count($rest) <= self::KEEP_AFTER_SUMMARY + 1) {
            return false; // nothing meaningful left to fold
        }

        // Never cut between a call and its results: a fixed-count cut can land
        // among tool results, opening the kept history on a result whose call
        // was folded away — which a hosted API rejects on every later request.
        // The cut moves back to the call instead.
        $split = count($rest) - self::KEEP_AFTER_SUMMARY;
        while ($split > 0 && ($rest[$split]['role'] ?? '') === 'tool') {
            $split--;
        }

        if ($split === 0) {
            return false; // the whole remainder is one exchange still in progress
        }

        $toSummarise = array_slice($rest, 0, $split);
        $kept = array_slice($rest, $split);

        $summary = $this->askForSummary($toSummarise);
        if ($summary === null) {
            return false;
        }

        $bag->replace(array_merge(
            $system,
            [[
                'role' => 'assistant',
                'content' => "[Summary of the earlier turns]\n" . $summary,
            ]],
            self::requestInProgress($toSummarise, $kept),
            $kept,
        ));

        return true;
    }

    /**
     * The request being worked on, when the cut falls inside its exchange. One
     * long exchange can fill the window on its own, and folding the question
     * into the summary leaves the model a paraphrase to work from — and the
     * session no user message to be saved under. So it stays, word for word.
     *
     * @param array<int, array<string, mixed>> $folded
     * @param array<int, array<string, mixed>> $kept
     *
     * @return array<int, array<string, mixed>>
     */
    private static function requestInProgress(array $folded, array $kept): array
    {
        foreach ($kept as $message) {
            if (($message['role'] ?? '') === 'user') {
                return [];
            }
        }

        for ($i = count($folded) - 1; $i >= 0; $i--) {
            if (($folded[$i]['role'] ?? '') === 'user') {
                return [$folded[$i]];
            }
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function askForSummary(array $messages): ?string
    {
        $transcript = [];
        foreach ($messages as $message) {
            $role = strtoupper((string) ($message['role'] ?? '?'));
            $content = (string) ($message['content'] ?? '');

            // Cap each entry: the point is to shrink context, and feeding the
            // whole history into the summariser can itself overflow the window.
            if (mb_strlen($content) > 1500) {
                $content = mb_substr($content, 0, 1500) . ' […]';
            }

            $transcript[] = "[{$role}] {$content}";
        }

        try {
            // stream(), with nothing listening: this used to be the blocking
            // chat(), where a Ctrl+C during compaction did nothing until the
            // pass finished, minutes on a slow machine.
            $message = $this->platform->stream([
                [
                    'role' => 'system',
                    'content' => 'Summarise the conversation in five sentences at most. Keep: '
                        . 'the files read or changed, the technical decisions taken, '
                        . 'and what is left to do. Be factual and concise.',
                ],
                ['role' => 'user', 'content' => implode("\n\n", $transcript)],
            ]);
        } catch (\Throwable) {
            return null;
        }

        // Cancelled mid-summary: the partial text is not a summary, and putting
        // it in place of the history would lose the history to a fragment.
        if ($this->interrupt?->requested()) {
            return null;
        }

        $summary = trim((string) ($message['content'] ?? ''));

        return $summary === '' ? null : $summary;
    }
}
