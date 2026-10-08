<?php

namespace App\Tool\Edit;

/**
 * Where file_write and file_patch put a file on disk, and say why when they
 * cannot.
 *
 * The usual reason is ownership, not a bug: a command run in the project's
 * container as root rewrote the file, and Sherpa, running as the user, may no
 * longer touch it. PHP's answer is a warning printed over the screen and a
 * false the model reads as "could not write". Told who owns the file and how
 * to give it back, the model asks the user for one chown — instead of going
 * around the refusal with sed in the container, which makes it worse.
 */
final class FileSaver
{
    /** @throws \RuntimeException saying what to do about it */
    public static function save(string $resolved, string $path, string $content): void
    {
        $dir = dirname($resolved);

        // Checked before trying: a refused mkdir() is a warning too.
        if (!is_dir($dir)) {
            // The deepest part that exists is the one whose owner refuses.
            [$parent, $shown] = [$dir, dirname($path)];
            while (!file_exists($parent) && dirname($parent) !== $parent) {
                [$parent, $shown] = [dirname($parent), dirname($shown)];
            }

            if (!is_dir($parent) || !is_writable($parent) || !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException(self::refusal($parent, $shown, 'directory', 'create ' . dirname($path)));
            }
        }

        if (file_exists($resolved) ? !is_writable($resolved) : !is_writable($dir)) {
            throw file_exists($resolved)
                ? new \RuntimeException(self::refusal($resolved, $path, 'file', 'write it'))
                : new \RuntimeException(self::refusal($dir, dirname($path), 'directory', 'create a file in it'));
        }

        if (@file_put_contents($resolved, $content) === false) {
            $why = error_get_last()['message'] ?? 'no reason given';

            throw new \RuntimeException("could not write {$path}: {$why}");
        }
    }

    private static function refusal(string $onDisk, string $shown, string $what, string $action): string
    {
        $owner = self::name(@fileowner($onDisk));
        $me = self::name(function_exists('posix_geteuid') ? posix_geteuid() : false);

        if ($owner === null || $me === null || $owner === $me) {
            return "the {$what} {$shown} is not writable, so Sherpa cannot {$action}. "
                . 'Tell the user; do not work around it with shell_exec.';
        }

        return "the {$what} {$shown} belongs to {$owner} and Sherpa runs as {$me}, so it cannot {$action}. "
            . 'A command run as another user — in the project\'s container, most likely — created it. '
            . "Ask the user to give it back (sudo chown -R {$me}: {$onDisk}); "
            . 'do not work around it with shell_exec.';
    }

    private static function name(int|false $uid): ?string
    {
        if ($uid === false) {
            return null;
        }

        $entry = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;

        return is_array($entry) ? $entry['name'] : (string) $uid;
    }
}
