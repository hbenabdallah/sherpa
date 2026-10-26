<?php

namespace App\Permission;

/**
 * Files that usually hold secrets: what is read goes to the model's provider,
 * so reading one is asked about every time rather than done unasked like any
 * other read. By name, not by content: a .env is a secret before anyone has
 * looked inside it. Templates of them (.env.example, .env.dist) are not.
 */
final class SecretFiles
{
    /** The forms of .env that are committed as examples, without values. */
    private const TEMPLATES = ['example', 'sample', 'dist', 'template', 'defaults'];

    private const NAMES = [
        '.envrc', '.netrc', '_netrc', '.npmrc', '.pypirc', '.git-credentials', '.htpasswd', '.pgpass',
        'id_rsa', 'id_dsa', 'id_ecdsa', 'id_ed25519', 'credentials', 'credentials.json', 'auth.json',
        'keys.yaml', 'secring.gpg', '.vault-token',
    ];

    private const EXTENSIONS = ['pem', 'key', 'p12', 'pfx', 'jks', 'keystore', 'kdbx', 'ovpn'];

    /** Directories whose files are all secrets. */
    private const DIRECTORIES = ['.ssh', '.aws', '.gnupg', '.kube', '.docker'];

    /** Why reading this path is asked about every time, or null for an ordinary file. */
    public static function reason(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        $name = basename($path);
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        $segments = explode('/', $path);
        array_pop($segments);
        $lower = strtolower($name);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $secret = match (true) {
            $lower === '.env' => true,
            str_starts_with($lower, '.env.') => !in_array(substr($lower, 5), self::TEMPLATES, true),
            in_array($lower, self::NAMES, true) => true,
            in_array($extension, self::EXTENSIONS, true) => true,
            preg_match('/^(client_)?secrets?([._-][^\/]*)?\.(json|ya?ml|txt|env|ini|toml|xml)$/', $lower) === 1 => true,
            preg_match('/^service[-_]?account.*\.json$/', $lower) === 1 => true,
            array_intersect($segments, self::DIRECTORIES) !== [] => true,
            default => false,
        };

        return $secret
            ? "{$name} usually holds secrets (keys, passwords), and what is read goes to the model's provider"
            : null;
    }
}
