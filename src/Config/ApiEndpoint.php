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

    /**
     * Whether a key sent to $url crosses a network in clear: plain http to a
     * host that is neither this machine nor a private network. A local server
     * on http is the norm; a provider typed with http:// instead of https://
     * hands the key to every hop on the way.
     *
     * Judged from the address as written, without a DNS lookup: a name that
     * resolves to a private address still counts as distant.
     */
    public static function sendsInClear(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'http') {
            return false;
        }

        return !self::isLocal($url);
    }

    /**
     * Whether $url is a server on this machine or its network — llama.cpp,
     * LM Studio, vLLM on the GPU box under the desk — rather than a provider.
     * Judged from the address as written, as above.
     */
    public static function isLocal(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower(trim(is_array($parts) ? $parts['host'] ?? '' : '', '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || !str_contains($host, '.') && !str_contains($host, ':')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }
}
