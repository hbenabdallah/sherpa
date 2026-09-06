<?php

namespace App\Memory;

use App\Agent\MessageBag;
use App\Platform\PlatformInterface;
use App\Runtime\Interrupt;

/**
 * Reads a finished conversation and keeps what is worth knowing next time.
 *
 * This is a backstop, not the main path: the model is expected to call
 * memory_remember while it works. It exists for the facts nobody thought to
 * write down, and it has to be cheap enough to be worth having — it runs after
 * someone has typed /exit and is waiting to get their shell back.
 */
class FactExtractor
{
    /**
     * Below this there is no session to summarise, only a greeting: system,
     * one question, one answer.
     */
    private const MIN_MESSAGES = 4;

    /**
     * How much of the tail to hand over.
     *
     * The transcript used to be everything, system prompt and tool results
     * included, with nothing standing between it and num_ctx. Overflowing that
     * is silent: llama.cpp drops tokens from the front, so the extraction
     * prompt loses its own instructions first and the model answers prose.
     */
    private const MAX_TRANSCRIPT_CHARS = 24000;

    /** A fact is a note to self, not a document. */
    private const MAX_KEY_CHARS = 80;
    private const MAX_VALUE_CHARS = 400;
    private const MAX_FACTS = 12;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly MemoryStore $store,
        private readonly Interrupt $interrupt,
    ) {}

    public function extract(MessageBag $bag): Extraction
    {
        if ($bag->count() < self::MIN_MESSAGES) {
            return Extraction::skipped('Session trop courte pour en retenir quoi que ce soit.');
        }

        $transcript = $this->transcribe($bag->all());
        if (trim($transcript) === '') {
            return Extraction::skipped('Rien à retenir de cette session.');
        }

        try {
            // Streamed rather than blocking, because stream() is the only path
            // that checks the interrupt: on CPU this pass takes as long as any
            // other, and it happens when someone is already leaving.
            $message = $this->platform->stream([
                ['role' => 'system', 'content' => 'Tu extrais des faits techniques durables d\'une conversation. Tu réponds uniquement par un tableau JSON.'],
                ['role' => 'user', 'content' => $this->prompt($transcript)],
            ]);
        } catch (\Throwable $e) {
            return Extraction::failed($e->getMessage());
        }

        if ($this->interrupt->requested()) {
            return Extraction::skipped('Extraction des faits interrompue.');
        }

        $facts = $this->decode($message['content'] ?? '');
        if ($facts === null) {
            return Extraction::failed('le modèle n\'a pas renvoyé de JSON exploitable');
        }

        return $this->persist($facts);
    }

    /**
     * The parts of the conversation that are actually evidence.
     *
     * The system message is Sherpa's own text and already contains the memory
     * summary — feeding it back invites the model to re-extract what it just
     * read. Tool results are file contents and command output: bulk, and not
     * something anyone decided.
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
        if (mb_strlen($transcript) > self::MAX_TRANSCRIPT_CHARS) {
            $transcript = '(début tronqué)' . "\n\n" . mb_substr($transcript, -self::MAX_TRANSCRIPT_CHARS);
        }

        return $transcript;
    }

    private function prompt(string $transcript): string
    {
        $max = self::MAX_FACTS;

        return <<<PROMPT
        Voici la fin d'une session de travail sur un projet PHP/Symfony.

        Extrais uniquement les faits DURABLES, ceux qui seront encore vrais dans un mois :
        conventions de code, décisions d'architecture, emplacements de fichiers importants,
        configurations spécifiques au projet.

        N'extrais PAS : ce qui a été fait pendant cette session, les questions posées,
        les erreurs corrigées, les faits déjà évidents à la lecture du code.

        Réponds UNIQUEMENT par un tableau JSON de {$max} objets maximum, chacun {"key": ..., "value": ...}.
        La clé est une étiquette courte en snake_case. S'il n'y a rien de durable, réponds [].

        Exemple : [{"key": "auth_strategy", "value": "JWT via lexik/jwt-authentication-bundle"}]

        Transcription :
        {$transcript}
        PROMPT;
    }

    /**
     * Find the JSON array in whatever the model actually said.
     *
     * A greedy /\[.*\]/s spans from the first bracket to the last one anywhere
     * in the reply, so a sentence like "voir [ici] et [là]" swallows the prose
     * between them and decodes to nothing. Small models also wrap the array in
     * fences, explain themselves first, and put brackets in their prose — so
     * the first balanced group is not necessarily the answer, and failing to
     * parse one is a reason to try the next, not to give up.
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
