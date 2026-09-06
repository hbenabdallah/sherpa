<?php

namespace App\Memory;

use App\Agent\ContextBudget;
use App\Agent\MessageBag;
use App\Platform\PlatformInterface;
use App\Runtime\Interrupt;

/**
 * Reads a finished conversation and keeps what is worth knowing next time: a
 * backstop for the facts nobody thought to write down, since the model is
 * expected to call memory_remember as it works. It runs after /exit, while
 * someone waits for their shell back, so it has to be cheap.
 */
class FactExtractor
{
    /**
     * Below this there is no session to summarise, only a greeting: system,
     * one question, one answer.
     */
    private const MIN_MESSAGES = 4;

    /**
     * How much of the tail to hand over. Overflowing the window is silent —
     * llama.cpp drops tokens from the front, so the extraction prompt loses its
     * own instructions and the model answers prose. A flat 24000 is right for
     * 32k and throws away most of a long session on anything larger, so it is
     * the floor of a share.
     */
    private const MIN_TRANSCRIPT_CHARS = 24000;
    private const MAX_TRANSCRIPT_CHARS = 120000;

    /** Share of the prompt window the transcript may occupy. */
    private const TRANSCRIPT_SHARE = 0.5;

    /** A fact is a note to self, not a document. */
    private const MAX_KEY_CHARS = 80;
    private const MAX_VALUE_CHARS = 400;
    private const MAX_FACTS = 12;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly MemoryStore $store,
        private readonly Interrupt $interrupt,
        // Optional so the extractor stays constructible on its own, as
        // memory_test builds it; absent, the floor applies.
        private readonly ?ContextBudget $budget = null,
    ) {}

    /** How many characters of transcript this window can afford. */
    private function transcriptBudget(): int
    {
        return $this->budget?->shareInChars(
            self::TRANSCRIPT_SHARE,
            self::MIN_TRANSCRIPT_CHARS,
            self::MAX_TRANSCRIPT_CHARS,
        ) ?? self::MIN_TRANSCRIPT_CHARS;
    }

    public function extract(MessageBag $bag): Extraction
    {
        if ($bag->count() < self::MIN_MESSAGES) {
            return Extraction::skipped('Session too short to keep anything from.');
        }

        $transcript = $this->transcribe($bag->all());
        if (trim($transcript) === '') {
            return Extraction::skipped('Nothing worth keeping from this session.');
        }

        try {
            // Streamed rather than blocking, because stream() is the only path
            // that checks the interrupt: on CPU this pass takes as long as any
            // other, and it happens when someone is already leaving.
            $message = $this->platform->stream([
                ['role' => 'system', 'content' => 'You pull lasting technical facts out of a conversation. You answer with a JSON array and nothing else.'],
                ['role' => 'user', 'content' => $this->prompt($transcript)],
            ]);
        } catch (\Throwable $e) {
            return Extraction::failed($e->getMessage());
        }

        if ($this->interrupt->requested()) {
            return Extraction::skipped('Fact extraction interrupted.');
        }

        $facts = $this->decode($message['content'] ?? '');
        if ($facts === null) {
            return Extraction::failed('the model did not return usable JSON');
        }

        return $this->persist($facts);
    }

    /**
     * The parts of the conversation that are evidence. The system message is
     * Sherpa's own text and already holds the memory digest, so feeding it back
     * invites re-extraction; tool results are bulk nobody decided.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function transcribe(array $messages): string
    {
        $lines = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? '';
            $content = trim((string) ($message['content'] ?? ''));

            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $lines[] = ($role === 'user' ? '[UTILISATEUR] ' : '[AGENT] ') . $content;
        }

        $transcript = implode("\n\n", $lines);

        // Keep the tail: what was decided late supersedes what was guessed early.
        $budget = $this->transcriptBudget();
        if (mb_strlen($transcript) > $budget) {
            $transcript = '(beginning cut)' . "\n\n" . mb_substr($transcript, -$budget);
        }

        return $transcript;
    }

    private function prompt(string $transcript): string
    {
        $max = self::MAX_FACTS;

        return <<<PROMPT
        Here is the end of a working session on a project.

        Pull out only the LASTING facts, the ones still true in a month: code
        conventions, architectural decisions, where important files live,
        configuration specific to this project.

        Do NOT pull out: what was done during this session, the questions asked,
        the mistakes fixed, or anything already obvious from reading the code.

        Answer with a JSON array of at most {$max} objects and nothing else, each {"key": ..., "value": ...}.
        The key is a short snake_case label. If nothing lasting came up, answer [].

        Example: [{"key": "auth_strategy", "value": "JWT via lexik/jwt-authentication-bundle"}]

        Transcript:
        {$transcript}
        PROMPT;
    }

    /**
     * Find the JSON array in whatever the model actually said. A greedy
     * /\[.*\]/s runs from the first bracket to the last anywhere in the reply,
     * so prose with brackets in it decodes to nothing; small models also fence
     * the array and explain themselves first. Failing to parse one balanced
     * group is a reason to try the next, not to give up.
     *
     * @return array<int, mixed>|null null when there is no array to be had
     */
    private function decode(string $content): ?array
    {
        $offset = 0;

        while (($start = strpos($content, '[', $offset)) !== false) {
            $end = $this->closingBracket($content, $start);

            if ($end !== null) {
                $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            $offset = $start + 1;
        }

        return null;
    }

    /**
     * The bracket that closes the one at $start, ignoring brackets inside
     * string literals so a "]" in a value does not end the array early.
     */
    private function closingBracket(string $content, int $start): ?int
    {
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start, $len = strlen($content); $i < $len; $i++) {
            $char = $content[$i];

            if ($inString) {
                $inString = !($char === '"' && !$escaped);
                $escaped = $char === '\\' && !$escaped;
                continue;
            }

            match ($char) {
                '"' => $inString = true,
                '[' => $depth++,
                ']' => $depth--,
                default => null,
            };

            if ($depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /** @param array<int, mixed> $facts */
    private function persist(array $facts): Extraction
    {
        $created = 0;
        $known = 0;
        $rejected = 0;

        foreach (array_slice($facts, 0, self::MAX_FACTS) as $fact) {
            $key = $this->clean($fact['key'] ?? null, self::MAX_KEY_CHARS);
            $value = $this->clean($fact['value'] ?? null, self::MAX_VALUE_CHARS);

            if ($key === null || $value === null) {
                $rejected++;
                continue;
            }

            // remember(), never supersede(): a model reading a transcript is in
            // no position to decide that something it did not see is now wrong.
            match ($this->store->remember($key, $value)) {
                Recorded::Created => $created++,
                default           => $known++,
            };
        }

        return Extraction::of($created, $known, $rejected);
    }

    private function clean(mixed $raw, int $max): ?string
    {
        if (is_bool($raw) || is_array($raw) || $raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        return $value === '' || mb_strlen($value) > $max ? null : $value;
    }
}
