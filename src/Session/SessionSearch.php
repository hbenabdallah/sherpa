<?php

declare(strict_types=1);

namespace App\Session;

use App\Agent\PlanMode;
use App\Rag\QueryTerms;
use Symfony\Component\String\UnicodeString;

/**
 * What was said in this project's earlier conversations, found by keywords:
 * what was decided, tried or given up, which the lasting facts leave out.
 *
 * Only what the user asked and what the model answered in words. Tool output
 * is left out: it is the project's files and commands, which the tools read
 * fresher than any copy, and it would bury the conversation under them.
 *
 * Ranked with BM25, computed here rather than by an index on disk: a project
 * keeps SessionStore::KEEP conversations, a megabyte or so, read in a few
 * milliseconds, and an index would be one more thing to keep in step with
 * files every turn rewrites.
 */
final readonly class SessionSearch
{
    private const int MAX_HITS = 6;

    /** BM25's usual constants: how fast a repeated word stops counting, and how much length weighs. */
    private const float K1 = 1.2;

    private const float B = 0.75;

    /** Below this length, a term is matched whole: "db" must not find "dbal". */
    private const int PREFIX_FROM = 4;

    private const int CONTEXT_LINES = 1;

    private const int MAX_LINES = 12;

    private const int LINE_CHARS = 300;

    private const int REPLY_CHARS = 400;

    public function __construct(private SessionStore $sessions) {}

    /**
     * The messages that match $query best, the best first; null when no
     * earlier conversation is saved, which is not the same answer as none
     * matching.
     *
     * @return list<SessionHit>|null
     */
    public function search(string $query): ?array
    {
        $earlier = array_values(array_filter(
            $this->sessions->all(),
            fn(SavedSession $session): bool => $session->id !== $this->sessions->currentId(),
        ));
        if ($earlier === []) {
            return null;
        }

        $terms = $this->terms($query);
        if ($terms === []) {
            return [];
        }

        $messages = $this->messages($earlier);
        $scores = $this->rank($messages, $terms);

        // A question and its answer often match together: each is shown
        // once, the answer under its question or on its own, not both.
        $hits = [];
        $shown = [];
        foreach (array_keys($scores) as $index) {
            $message = $messages[$index];
            $id = $message['session']->id;
            if (isset($shown[$id][$message['position']])) {
                continue;
            }
            $shown[$id][$message['position']] = true;

            $reply = null;
            if ($message['role'] === 'user') {
                $reply = $this->replyTo($message['session'], $message['position']);
            }
            if ($reply !== null && isset($shown[$id][$reply[0]])) {
                $reply = null;
            }
            if ($reply !== null) {
                $shown[$id][$reply[0]] = true;
            }

            $hits[] = new SessionHit($message['session'], $message['role'], $this->excerpt($message['text'], $terms), $reply[1] ?? null);
            if (count($hits) === self::MAX_HITS) {
                break;
            }
        }

        return $hits;
    }

    /**
     * The query's words, folded as the messages are, each with what it
     * matches: the word itself, or the start of every word for a long one.
     *
     * @return array<string, string> word ⇒ stem
     */
    private function terms(string $query): array
    {
        $terms = [];
        foreach (QueryTerms::of($query) as $word) {
            $folded = self::fold($word);
            $terms[$folded] = mb_strlen($folded) < self::PREFIX_FROM ? $folded : QueryTerms::stems([$folded])[0];
        }

        return $terms;
    }

    /**
     * Every message worth searching, in the order of the conversations.
     *
     * @param list<SavedSession> $sessions
     *
     * @return list<array{session: SavedSession, position: int, role: string, text: string, words: array<string, int>, length: int}>
     */
    private function messages(array $sessions): array
    {
        $messages = [];
        foreach ($sessions as $session) {
            foreach ($session->messages as $position => $message) {
                $text = self::said($message);
                if ($text === null) {
                    continue;
                }

                preg_match_all('/[\p{L}\p{N}_]+/u', self::fold($text), $matches);
                $messages[] = [
                    'session'  => $session,
                    'position' => $position,
                    'role'     => (string) $message['role'],
                    'text'     => $text,
                    'words'    => array_count_values($matches[0]),
                    'length'   => count($matches[0]),
                ];
            }
        }

        return $messages;
    }

    /**
     * What a message said in words, or null for a tool's output or a call
     * with nothing written beside it.
     *
     * @param array<string, mixed> $message
     */
    private static function said(array $message): ?string
    {
        $role = $message['role'] ?? '';
        $content = $message['content'] ?? null;
        if (!in_array($role, ['user', 'assistant'], true) || !is_string($content)) {
            return null;
        }

        $text = trim($role === 'user' ? PlanMode::unframe($content) : $content);

        return $text === '' ? null : $text;
    }

    /**
     * Each matching message's BM25 score, the highest first.
     *
     * @param list<array{session: SavedSession, position: int, role: string, text: string, words: array<string, int>, length: int}> $messages
     * @param array<string, string>                                                                                               $terms
     *
     * @return array<int, float> message index ⇒ score
     */
    private function rank(array $messages, array $terms): array
    {
        $count = count($messages);
        if ($count === 0) {
            return [];
        }

        $averageLength = max(1, array_sum(array_column($messages, 'length')) / $count);

        $frequencies = [];
        foreach ($messages as $index => $message) {
            foreach ($terms as $term => $stem) {
                $frequency = $this->frequency($message['words'], $term, $stem);
                if ($frequency > 0) {
                    $frequencies[$term][$index] = $frequency;
                }
            }
        }

        $scores = [];
        foreach ($frequencies as $byMessage) {
            $found = count($byMessage);
            $rarity = log(1 + ($count - $found + 0.5) / ($found + 0.5));

            foreach ($byMessage as $index => $frequency) {
                $norm = 1 - self::B + self::B * $messages[$index]['length'] / $averageLength;
                $scores[$index] = ($scores[$index] ?? 0.0) + $rarity * $frequency * (self::K1 + 1) / ($frequency + self::K1 * $norm);
            }
        }

        arsort($scores);

        return $scores;
    }

    /** @param array<string, int> $words */
    private function frequency(array $words, string $term, string $stem): int
    {
        if (mb_strlen($term) < self::PREFIX_FROM) {
            return $words[$term] ?? 0;
        }

        $frequency = 0;
        foreach ($words as $word => $times) {
            if (str_starts_with((string) $word, $stem)) {
                $frequency += $times;
            }
        }

        return $frequency;
    }

    /**
     * The first answer in words after the question at $position: where it
     * is, and its start.
     *
     * @return array{0: int, 1: string}|null
     */
    private function replyTo(SavedSession $session, int $position): ?array
    {
        for ($next = $position + 1; $next < count($session->messages); $next++) {
            $message = $session->messages[$next];
            if (($message['role'] ?? '') === 'user') {
                return null;
            }

            $text = self::said($message);
            if ($text !== null) {
                return [$next, self::cut($text, self::REPLY_CHARS)];
            }
        }

        return null;
    }

    /**
     * The lines that hold a term, with one either side; the whole message
     * when it is short enough to be its own excerpt.
     *
     * @param array<string, string> $terms
     */
    private function excerpt(string $text, array $terms): string
    {
        $lines = explode("\n", $text);
        if (count($lines) <= self::MAX_LINES) {
            return implode("\n", array_map(static fn(string $line): string => self::cut($line, self::LINE_CHARS), $lines));
        }

        $keep = [];
        foreach ($lines as $i => $line) {
            $folded = self::fold($line);
            foreach ($terms as $stem) {
                if (str_contains($folded, $stem)) {
                    for ($j = max(0, $i - self::CONTEXT_LINES); $j <= min(count($lines) - 1, $i + self::CONTEXT_LINES); $j++) {
                        $keep[$j] = true;
                    }
                    break;
                }
            }
        }
        ksort($keep);

        $out = [];
        $previous = null;
        foreach (array_slice(array_keys($keep), 0, self::MAX_LINES) as $i) {
            if ($previous !== null && $i > $previous + 1) {
                $out[] = '…';
            }
            $out[] = self::cut($lines[$i], self::LINE_CHARS);
            $previous = $i;
        }

        return implode("\n", $out);
    }

    private static function cut(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? mb_substr($text, 0, $chars - 1) . '…' : $text;
    }

    /** Lower case, without accents: "décidé" is found by "decide", as the user may type it either way. */
    private static function fold(string $text): string
    {
        return preg_match('/[^\x00-\x7F]/', $text) === 1
            ? mb_strtolower(new UnicodeString($text)->ascii()->toString())
            : strtolower($text);
    }
}
