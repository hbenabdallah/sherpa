<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * How much room a provider says is left, as it says it in the headers of each
 * reply — nothing guessed. Three families are read:
 *
 *     x-ratelimit-limit-tokens / x-ratelimit-remaining-tokens     OpenAI, Groq, Mistral…
 *     anthropic-ratelimit-tokens-limit / …-tokens-remaining         Anthropic
 *     ratelimit-limit / ratelimit-remaining                         the IETF draft, OpenRouter…
 *
 * A provider that sends none (NVIDIA, Cloudflare) gets no figure at all,
 * rather than one that would look like a measurement.
 */
final class RateLimits
{
    /**
     * @param array<string, array{remaining: int, limit: int, reset: ?string}> $windows by name
     */
    private function __construct(public readonly array $windows) {}

    /**
     * @param array<string, list<string>|string> $headers as the HTTP client hands them, names lowercase
     */
    public static function fromHeaders(array $headers): ?self
    {
        $values = [];
        foreach ($headers as $name => $value) {
            $values[strtolower((string) $name)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }

        $found = [];
        foreach ($values as $name => $value) {
            if (preg_match('/^(?:x-)?ratelimit-(limit|remaining|reset)(?:-(.+))?$/', $name, $m) === 1) {
                $found[$m[2] ?? ''][$m[1]] = $value;
            } elseif (preg_match('/^anthropic-ratelimit-(.+)-(limit|remaining|reset)$/', $name, $m) === 1) {
                $found[$m[1]][$m[2]] = $value;
            }
        }

        $windows = [];
        foreach ($found as $key => $parts) {
            // The IETF draft allows "100, 100;w=60": the first number is the one.
            $limit = self::number($parts['limit'] ?? '');
            $remaining = self::number($parts['remaining'] ?? '');

            if ($limit === null || $limit <= 0 || $remaining === null) {
                continue;
            }

            $windows[self::label((string) $key)] = [
                'remaining' => max(0, min($remaining, $limit)),
                'limit'     => $limit,
                'reset'     => isset($parts['reset']) && trim($parts['reset']) !== '' ? trim($parts['reset']) : null,
            ];
        }

        return $windows === [] ? null : new self($windows);
    }

    /** The window with the least room left, as a share of it. */
    public function tightest(): array
    {
        $name = null;
        $share = 2.0;
        foreach ($this->windows as $window => $w) {
            $s = $w['remaining'] / $w['limit'];
            if ($s < $share) {
                [$name, $share] = [$window, $s];
            }
        }

        return ['name' => (string) $name, 'percentLeft' => (int) floor($share * 100)];
    }

    private static function number(string $value): ?int
    {
        return preg_match('/^\s*(\d+)/', $value, $m) === 1 ? (int) $m[1] : null;
    }

    /** "tokens-month" → "tokens/month", "" → "requests" (the bare header counts requests). */
    private static function label(string $key): string
    {
        if ($key === '') {
            return 'requests';
        }

        $key = str_replace(['-minute', '-min', '-day', '-month', '-hour'], ['/min', '/min', '/day', '/month', '/hour'], $key);

        return str_replace('-', ' ', $key);
    }
}
