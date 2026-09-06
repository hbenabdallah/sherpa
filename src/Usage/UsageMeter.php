<?php

declare(strict_types=1);

namespace App\Usage;

/**
 * What this session has consumed, and what it has cost.
 *
 * On a local model a token costs time; on an API it costs money, and the API
 * is the default. Every request — the agent's turns, but also the compactor's
 * summaries and the fact extraction at the end — goes through the platform
 * switch, which is where this is fed, so nothing is left out of the count.
 *
 * The cost is accumulated request by request at the price in force when each
 * was made, because a session can change model halfway through.
 */
final class UsageMeter
{
    /** Share of the cap at which the user is told, once, that it is close. */
    private const WARN_AT = 0.8;

    private int $requests = 0;
    private int $prompt = 0;
    private int $completion = 0;
    private int $cached = 0;

    private float $cost = 0.0;
    private int $unpriced = 0;
    private ?Price $price = null;

    private bool $warned = false;

    public function __construct(
        /** Tokens, sent plus received, a session may use; 0 for no cap. */
        private readonly int $cap = 0,
    ) {}

    /** The price of the model now in force, or null when none is declared. */
    public function usePrice(?Price $price): void
    {
        $this->price = $price;
    }

    public function price(): ?Price
    {
        return $this->price;
    }

    /** @param array{prompt: int, completion: int, cached?: int} $usage */
    public function record(array $usage): void
    {
        $prompt = max(0, (int) $usage['prompt']);
        $completion = max(0, (int) $usage['completion']);
        $cached = max(0, (int) ($usage['cached'] ?? 0));

        $this->requests++;
        $this->prompt += $prompt;
        $this->completion += $completion;
        $this->cached += $cached;

        if ($this->price === null) {
            $this->unpriced++;
        } else {
            $this->cost += $this->price->of($prompt, $completion, $cached);
        }
    }

    /**
     * Refuse the next request once the cap is spent.
     *
     * @throws TokenCapReached
     */
    public function assertRoom(): void
    {
        if ($this->cap > 0 && $this->total() >= $this->cap) {
            throw new TokenCapReached(sprintf(
                'Cap of %s tokens reached for this session (SHERPA_MAX_SESSION_TOKENS): '
                . 'nothing was sent. Start Sherpa again, or raise the cap.',
                self::number($this->cap),
            ));
        }
    }

    /**
     * True once, the first time the session passes most of its cap — and not
     * at all when one request went straight past it: the refusal that follows
     * says it better than "1 886 des 1 500 tokens".
     */
    public function shouldWarn(): bool
    {
        if ($this->warned || $this->cap <= 0 || $this->total() < $this->cap * self::WARN_AT) {
            return false;
        }

        $this->warned = true;

        return $this->total() < $this->cap;
    }

    public function requests(): int { return $this->requests; }
    public function prompt(): int { return $this->prompt; }
    public function completion(): int { return $this->completion; }
    public function cached(): int { return $this->cached; }
    public function cap(): int { return $this->cap; }

    public function total(): int
    {
        return $this->prompt + $this->completion;
    }

    /** Null when no request of this session had a price. */
    public function cost(): ?float
    {
        return $this->requests > $this->unpriced ? $this->cost : null;
    }

    /** Requests made while no price was declared, left out of cost(). */
    public function unpriced(): int
    {
        return $this->unpriced;
    }

    /** "~0,0123 $", or null when there is nothing priced to show. */
    public function costLabel(): ?string
    {
        $cost = $this->cost();
        if ($cost === null) {
            return null;
        }

        $currency = $this->price?->currency ?? '$';

        return '~' . number_format($cost, $cost < 1 ? 4 : 2, '.', ',') . ' ' . $currency;
    }

    /** For the status bar: "45,2k tokens · ~0,0123 $", or null before any request. */
    public function statusLabel(): ?string
    {
        if ($this->requests === 0) {
            return null;
        }

        $label = self::short($this->total()) . ' tokens';
        $cost = $this->costLabel();

        return $cost === null ? $label : $label . ' · ' . $cost;
    }

    /** For the end of the session: requests, tokens, and cost when known. */
    public function summary(): string
    {
        $parts = [
            $this->requests . ' request' . ($this->requests > 1 ? 's' : ''),
            self::number($this->total()) . ' tokens',
        ];

        if (($cost = $this->costLabel()) !== null) {
            $parts[] = $cost . ($this->unpriced > 0 ? " ({$this->unpriced} unpriced left out)" : '');
        }

        return 'Session: ' . implode(' · ', $parts);
    }

    public static function number(int $n): string
    {
        return number_format($n, 0, '.', ',');
    }

    private static function short(int $n): string
    {
        return $n >= 1000
            ? rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.') . 'k'
            : (string) $n;
    }
}
