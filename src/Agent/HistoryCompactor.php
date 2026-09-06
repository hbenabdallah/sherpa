<?php

declare(strict_types=1);

namespace App\Agent;

use App\Memory\ContextStore;
use App\Platform\PlatformInterface;
use App\Runtime\Interrupt;

/**
 * Keeps the conversation inside the context window.
 *
 * Two ways to shrink it, and the interesting question is not which is cheaper
 * but which is cheaper *here*:
 *
 *   1. Elide old tool output. In an agent loop this is nearly always where the
 *      bulk sits — a single file_read of a 400-line controller dwarfs the entire
 *      surrounding conversation. Free, and it throws the content away: if the
 *      model needs it again it re-reads the file, which is another turn and
 *      another full prompt evaluation.
 *   2. Summarise the oldest turns via the model. Costs a whole forward pass,
 *      and keeps the conclusions rather than the transcript.
 *
 * Stage 1 was unconditionally first, on the grounds that a forward pass is
 * expensive. It is, on a CPU. On a machine with a graphics card it is a second
 * or two, and cheaper than the extra turn that eliding provokes — so the order
 * is decided by InferenceSpeed against a wall-clock budget instead of being
 * frozen in the method body. An unmeasured machine gets the frugal order, which
 * is what a slow one wants anyway.
 *
 * Whichever runs, compaction aims at ContextBudget::compactTarget() rather than
 * merely dropping back under the trigger: rewriting history from the front
 * invalidates the server's prefix cache, so the whole window is re-read on the
 * next request. That is the single largest recurring cost in a long session,
 * and it is paid per compaction — so compact rarely and deeply, never often and
 * shallowly.
 *
 * The system message is never touched: it carries the identity, project facts
 * and skills index, and it is precisely what llama.cpp would discard first if
 * the window overflowed.
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
     * How long a summarising pass may be expected to take before it stops being
     * the better option.
     *
     * Wall clock, not tokens per second: the threshold that matters is how long
     * someone sits in front of a silent terminal. Twenty seconds is roughly a
     * 30B model reading a full window on a mid-range card, and roughly a
     * thousandth of what the same pass costs on a laptop CPU — which is why one
     * of them summarises and the other elides.
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
                $actions[] = 'historique résumé';
            }
        }

        // Skipped entirely when the summary already got us under target, which
        // is the point of doing it first: recent tool output survives intact.
        if ($this->budget->isAboveTarget($bag->all(), $toolSchemas)) {
            $elided = $this->elideOldToolOutputs($bag);
            if ($elided > 0) {
                $actions[] = "{$elided} sortie(s) d'outil élidée(s)";
            }
        }

        if (!$summarised
            && $this->budget->isAboveTarget($bag->all(), $toolSchemas)
            && $this->summariseOldTurns($bag)
        ) {
            $actions[] = 'historique résumé';
        }

        return $actions;
    }

    /**
     * Whether asking the model to summarise is worth doing before eliding.
     *
     * Unmeasured means the frugal answer: the first turns of a session have no
     * timings yet, and guessing "fast" there would spend a minute of somebody's
     * CPU to find out it was wrong.
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
     * Move the body of aged-out tool results out of the window.
     *
     * The stub left behind used to be the whole story, and the only way back to
     * the content was to run the tool again. Now the body is handed to
     * ContextStore on its way out and the stub carries the id that retrieves
     * it: the same saving, without the loss.
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
                    '[… sortie de %s élidée pour économiser le contexte : %d lignes, %d caractères. '
                    . 'Rappelle l\'outil si tu as besoin du détail.]',
                    $name,
                    $lines,
                    mb_strlen($content),
                )
                : sprintf(
                    '[… sortie de %s élidée pour économiser le contexte : %d lignes, %d caractères. '
                    . 'Toujours consultable sans relancer l\'outil : context_recall(search: "…") '
                    . 'ou context_recall(id: %d).]',
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

        $toSummarise = array_slice($rest, 0, count($rest) - self::KEEP_AFTER_SUMMARY);
        $kept = array_slice($rest, -self::KEEP_AFTER_SUMMARY);

        $summary = $this->askForSummary($toSummarise);
        if ($summary === null) {
            return false;
        }

        $bag->replace(array_merge(
            $system,
            [[
                'role' => 'assistant',
                'content' => "[Résumé des tours précédents]\n" . $summary,
            ]],
            $kept,
        ));

        return true;
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
            // stream(), with nothing listening. This used to be chat(), which
            // blocks with no cancellation checkpoint anywhere in it: a Ctrl+C
            // during a compaction did nothing at all until the pass finished,
            // which on a slow machine is minutes. FactExtractor had already hit
            // this and solved it the same way.
            $message = $this->platform->stream([
                [
                    'role' => 'system',
                    'content' => 'Résume la conversation en 5 phrases maximum. Retiens : '
                        . 'les fichiers consultés ou modifiés, les décisions techniques prises, '
                        . 'et ce qui reste à faire. Sois factuel et concis.',
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
