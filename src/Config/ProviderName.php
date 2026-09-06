<?php

declare(strict_types=1);

namespace App\Config;

/**
 * What an API provider is called in config.yaml and after /provider: a short
 * word, typed often. Suggested from the address — api.groq.com is "groq" —
 * so the usual answer to "which name?" is Enter.
 */
final class ProviderName
{
    /** Host labels that name nothing: the API's own subdomain, and the like. */
    private const GENERIC = ['api', 'www'];

    public static function fromUrl(?string $url): string
    {
        $host = $url !== null ? strtolower((string) parse_url($url, PHP_URL_HOST)) : '';
        if ($host === '') {
            return 'default';
        }

        $labels = explode('.', $host);
        // The top-level domain, when there is one: "com" names nobody.
        if (count($labels) > 1 && !filter_var($host, FILTER_VALIDATE_IP)) {
            array_pop($labels);
        }
        $labels = array_values(array_diff($labels, self::GENERIC));

        return self::normalize((string) end($labels)) ?? 'default';
    }

    /** The name as stored, or null when $typed cannot be one. */
    public static function normalize(string $typed): ?string
    {
        $name = strtolower(trim($typed));

        return preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $name) === 1 ? $name : null;
    }

    /** The variable a provider's key is suggested in: GROQ_API_KEY for "groq". */
    public static function keyVariable(string $name): string
    {
        return strtoupper(str_replace('-', '_', $name)) . '_API_KEY';
    }
}
