<?php

declare(strict_types=1);

namespace App\Agent;

use App\Platform\PlatformInterface;

/**
 * Keeps the conversation inside the context window.
 *
 * Two stages, cheapest first. Stage one costs nothing on any machine; stage two
 * is a whole extra forward pass, whose price varies by two orders of magnitude
 * between a GPU and a CPU but is never zero on either:
 *
 *   1. Elide old tool output. In an agent loop this is nearly always where the
 *      bulk sits — a single file_read of a 400-line controller dwarfs the entire
 *      surrounding conversation, and once the model has drawn its conclusions
 *      the raw text has served its purpose. Free, and usually sufficient.
 *   2. Summarise the oldest turns via the model, but only if stage 1 was not
 *      enough.
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

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly ContextBudget $budget,
    ) {}

    /**
     * @param  array<int, array<string, mixed>> $toolSchemas
     * @return string[] human-readable description of what was done
     */
    public function compact(MessageBag $bag, array $toolSchemas = []): array
    {
        $actions = [];

        $elided = $this->elideOldToolOutputs($bag);
        if ($elided > 0) {
            $actions[] = "{$elided} sortie(s) d'outil élidée(s)";
        }

        if (!$this->budget->needsCompaction($bag->all(), $toolSchemas)) {
            return $actions;
        }

        if ($this->summariseOldTurns($bag)) {
            $actions[] = 'historique résumé';
        }

        return $actions;
    }

    /**
     * Replace the body of aged-out tool results with a stub recording what was
     * there, so the model knows the step happened rather than silently losing it.
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
            $name = $message['name'] ?? 'tool';

            $messages[$i]['content'] = sprintf(
                '[… sortie de %s élidée pour économiser le contexte : %d lignes, %d caractères. '
                . 'Rappelle l\'outil si tu as besoin du détail.]',
                $name,
                $lines,
                mb_strlen($content),
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
            $message = $this->platform->chat([
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

        $summary = trim((string) ($message['content'] ?? ''));

        return $summary === '' ? null : $summary;
    }
}
