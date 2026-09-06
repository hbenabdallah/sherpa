<?php

declare(strict_types=1);

namespace App\Config;

/**
 * An API address as someone types it, turned into the one Sherpa needs.
 *
 * What gets pasted is rarely the base URL. It is the full endpoint copied from
 * a curl example, with /chat/completions on the end; or it has a trailing
 * slash; or the scheme is missing. Each of those has one obvious reading, and
 * asking again for it would be pedantry. Anything that is not an http(s)
 * address at all is refused, so a typo is caught at the question rather than
 * at the first request.
 */
final class ApiEndpoint
{
    /** Suffixes copied along with the base URL, from a provider's own examples. */
    private const COPIED_SUFFIXES = ['/chat/completions', '/completions', '/models'];

    /** The base URL, or null when $typed is not an http(s) address. */
    public static function normalize(string $typed): ?string
    {
        $url = trim($typed);
        if ($url === '' || preg_match('/\s/', $url) === 1) {
            return null;
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            // A local server is typed as "localhost:8000/v1" more often than not.
            $url = (preg_match('#^(localhost|127\.|\[::1\])#i', $url) === 1 ? 'http://' : 'https://') . $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        $url = rtrim($url, '/');
        foreach (self::COPIED_SUFFIXES as $suffix) {
            if (str_ends_with(strtolower($url), $suffix)) {
                $url = substr($url, 0, -strlen($suffix));
                break;
            }
        }

        return rtrim($url, '/');
    }
}
