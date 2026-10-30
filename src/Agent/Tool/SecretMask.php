<?php

namespace App\Agent\Tool;

/**
 * Credentials taken out of what a tool returns, before it goes to the
 * provider: a key printed by a command, read in a file or fetched from a page
 * is replaced by a marker saying what it was. Only shapes that cannot be
 * anything else — a provider's key prefix, a private key block — so ordinary
 * code and output come through untouched.
 */
final class SecretMask
{
    public const MARKER = '[secret masked:';

    /** @var array<string, string> pattern => what it is */
    private const PATTERNS = [
        '/-----BEGIN (?:[A-Z]+ )*PRIVATE KEY-----.*?-----END (?:[A-Z]+ )*PRIVATE KEY-----/s' => 'private key',
        '/\bsk-ant-[A-Za-z0-9_-]{20,}/' => 'Anthropic key',
        '/\bsk-(?:proj-|svcacct-|admin-)?[A-Za-z0-9_-]{32,}/' => 'OpenAI key',
        '/\bgh[pousr]_[A-Za-z0-9]{36,}/' => 'GitHub token',
        '/\bgithub_pat_[A-Za-z0-9_]{22,}/' => 'GitHub token',
        '/\bglpat-[A-Za-z0-9_-]{20,}/' => 'GitLab token',
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/' => 'AWS key id',
        '/(?<=aws_secret_access_key)(\s*[=:]\s*["\']?)[A-Za-z0-9\/+=]{40}/i' => 'AWS secret',
        '/\bxox[abprs]-[A-Za-z0-9-]{10,}/' => 'Slack token',
        '/\bAIza[0-9A-Za-z_-]{35}/' => 'Google API key',
        '/\b(?:sk|rk)_live_[0-9A-Za-z]{20,}/' => 'Stripe key',
        '/\bnvapi-[A-Za-z0-9_-]{20,}/' => 'NVIDIA key',
        '/\bhf_[A-Za-z0-9]{30,}/' => 'Hugging Face token',
        '/\bgsk_[A-Za-z0-9]{20,}/' => 'Groq key',
        '/\bnpm_[A-Za-z0-9]{36}\b/' => 'npm token',
    ];

    public static function mask(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $what) {
            $text = (string) preg_replace_callback(
                $pattern,
                // The AWS secret keeps what comes before it: the name and the "=".
                static fn(array $m): string => ($m[1] ?? '') . self::MARKER . " {$what}]",
                $text,
            );
        }

        return $text;
    }

    /** Whether a text holds a marker: written back, it would replace the secret with it. */
    public static function holdsMarker(string $text): bool
    {
        return str_contains($text, self::MARKER);
    }
}
