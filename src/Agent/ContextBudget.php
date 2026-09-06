<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * How much of the model's context window the conversation is consuming.
 *
 * No tokenizer in PHP, so an outgoing request can only be estimated from
 * characters — then the ratio is corrected from the count the server reports,
 * and after a couple of turns the estimate tracks reality whatever the language.
 *
 * Going over does not raise an error: llama.cpp discards tokens from the
 * *start* of the prompt, where the identity, the project memory and the skills
 * index live, and the agent appears to forget who it is. Everywhere else it is
 * latency — every token in the window is compute somebody waits on.
 */
final class ContextBudget
{
    /**
     * Conservative starting point. Prose runs ~4 chars/token, source code and
     * JSON are denser at ~3; tool-heavy agent traffic sits between the two.
     */
    private const INITIAL_CHARS_PER_TOKEN = 3.4;

    /** Per-message overhead for chat-template markers (<|im_start|>role etc.). */
    private const MESSAGE_OVERHEAD_TOKENS = 5;

    private float $charsPerToken = self::INITIAL_CHARS_PER_TOKEN;
    private int $calibrations = 0;
    private ?int $lastMeasured = null;

    /**
     * Which model the calibration below was learned from. Null until the first
     * binding; see useModel().
     */
    private ?string $model = null;

    public function __construct(
        // Not readonly: /model changes it mid-session, and the estimate has to
        // change with it.
        private int $contextWindow = 32768,
        private readonly int $reservedForResponse = 4096,
        private readonly float $compactAtFraction = 0.75,
        // Where compaction stops, distinct from where it starts: the same
        // number meant every compaction freed just enough to cross the trigger
        // again next turn, each crossing costing a full re-read.
        private readonly float $compactToFraction = 0.50,
    ) {}

    /** Tokens available for the prompt, once room for the reply is set aside. */
    public function promptLimit(): int
    {
        return max(1, $this->contextWindow - $this->reservedForResponse);
    }

    /** Crossing this triggers compaction, leaving headroom to do it in. */
    public function compactThreshold(): int
    {
        return (int) ($this->promptLimit() * $this->compactAtFraction);
    }

    /** Where a compaction aims to land, so the next one is far off. */
    public function compactTarget(): int
    {
        return (int) ($this->promptLimit() * min($this->compactToFraction, $this->compactAtFraction));
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $toolSchemas
     */
    public function isAboveTarget(array $messages, array $toolSchemas = []): bool
    {
        return $this->estimateRequest($messages, $toolSchemas) > $this->compactTarget();
    }

    /**
     * A character allowance sized as a share of the window rather than fixed: a
     * constant chosen as "about this much of the window" stops being that the
     * moment the window changes, and they were all chosen against 32k. The
     * floor keeps that machine as it was; the ceiling keeps the rest bounded.
     */
    public function shareInChars(float $share, int $minChars, int $maxChars): int
    {
        $chars = (int) ($this->promptLimit() * $share * $this->charsPerToken());

        return max($minChars, min($maxChars, $chars));
    }

    public function contextWindow(): int
    {
        return $this->contextWindow;
    }

    public function estimateText(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return (int) ceil(mb_strlen($text) / $this->charsPerToken);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    public function estimateMessages(array $messages): int
    {
        $total = 0;

        foreach ($messages as $message) {
            $total += self::MESSAGE_OVERHEAD_TOKENS;
            $total += $this->estimateText((string) ($message['content'] ?? ''));

            // Tool calls travel as JSON and are far from free.
            if (!empty($message['tool_calls'])) {
                $total += $this->estimateText(json_encode($message['tool_calls']) ?: '');
            }
        }

        return $total;
    }

    /**
     * @param array<int, array<string, mixed>> $toolSchemas
     */
    public function estimateTools(array $toolSchemas): int
    {
        return $toolSchemas === [] ? 0 : $this->estimateText(json_encode($toolSchemas) ?: '');
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $toolSchemas
     */
    public function estimateRequest(array $messages, array $toolSchemas = []): int
    {
        return $this->estimateMessages($messages) + $this->estimateTools($toolSchemas);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $toolSchemas
     */
    public function needsCompaction(array $messages, array $toolSchemas = []): bool
    {
        return $this->estimateRequest($messages, $toolSchemas) > $this->compactThreshold();
    }

    /**
     * Correct the ratio using a real prompt token count from the server.
     *
     * Smoothed, because a single turn is noisy — one large tool result can skew
     * the density badly in either direction.
     */
    public function calibrate(int $actualPromptTokens, array $messages, array $toolSchemas = []): void
    {
        if ($actualPromptTokens <= 0) {
            return;
        }

        $this->lastMeasured = $actualPromptTokens;

        $chars = mb_strlen(json_encode($messages) ?: '') + mb_strlen(json_encode($toolSchemas) ?: '');
        if ($chars <= 0) {
            return;
        }

        $observed = $chars / $actualPromptTokens;

        // Ignore implausible ratios; a truncated or failed request can report
        // counts that have nothing to do with what we sent.
        if ($observed < 1.5 || $observed > 12.0) {
            return;
        }

        $this->calibrations++;
        $weight = $this->calibrations === 1 ? 1.0 : 0.3;

        $this->charsPerToken = ($this->charsPerToken * (1 - $weight)) + ($observed * $weight);
    }

    /**
     * Bind the budget to a model and its window. Chars-per-token belongs to the
     * *tokenizer*, so carrying it across a switch measures the next model with
     * the previous one's ruler: a change of model drops the calibration and the
     * next two turns rebuild it. A change of window alone keeps it.
     */
    public function useModel(string $model, int $contextWindow): void
    {
        if ($contextWindow > 0) {
            $this->contextWindow = $contextWindow;
        }

        $model = trim($model);
        if ($model === '' || $model === $this->model) {
            return;
        }

        $this->model = $model;
        $this->charsPerToken = self::INITIAL_CHARS_PER_TOKEN;
        $this->calibrations = 0;
        $this->lastMeasured = null;
    }

    /** Real prompt size of the last request, when the server has reported one. */
    public function lastMeasured(): ?int
    {
        return $this->lastMeasured;
    }

    public function charsPerToken(): float
    {
        return $this->charsPerToken;
    }

    public function isCalibrated(): bool
    {
        return $this->calibrations > 0;
    }
}
