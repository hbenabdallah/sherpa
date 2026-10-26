<?php

namespace App\Permission;

/**
 * What a grant for shell_exec covers. "Always" used to grant the tool, so one
 * answer about `make test` let every command run unasked from then on, rm -rf
 * included. A grant now names a command: `make test *` — that program and
 * that first argument, with any arguments after — or the command exactly.
 *
 * Some commands are asked about every time whatever was granted, and only
 * ever allowed once: those that cannot be taken back (a recursive delete, a
 * push, a reset), those that run as someone else, and those whose words do not
 * say what runs (see ShellCommand::$opaque).
 */
final class CommandPolicy
{
    /** The tool these grants are for. */
    public const TOOL = 'shell_exec';

    /** How a grant for one command is kept beside the grants for whole tools. */
    public const PREFIX = self::TOOL . ': ';

    /** Programs that run another command, given after their own options. */
    private const WRAPPERS = ['command', 'builtin', 'exec', 'env', 'nice', 'nohup', 'time', 'timeout', 'stdbuf', 'ionice', 'xargs', 'watch', 'unbuffer'];

    /**
     * Programs whose first argument does not say what they will do: a grant
     * for one of them covers that exact command, never what follows it.
     */
    private const EXACT_ONLY = [
        'sh', 'bash', 'zsh', 'dash', 'ksh', 'fish', 'find', 'curl', 'wget',
        'docker', 'podman', 'kubectl', 'ssh', 'scp', 'rsync', 'npx', 'pnpx', 'bunx', 'sed', 'awk', 'perl',
    ];

    /** Programs that run what they read, when they are given no script. */
    private const INTERPRETERS = ['sh', 'bash', 'zsh', 'dash', 'ksh', 'fish', 'python', 'python3', 'perl', 'ruby', 'php', 'node'];

    /** Why this command is asked about every time and allowed once at most, or null. */
    public static function onlyOnce(ShellCommand $command): ?string
    {
        if ($command->segments === []) {
            return 'it runs nothing';
        }

        foreach ($command->segments as $i => $words) {
            $danger = self::danger(self::core($words), $command->piped[$i]);
            if ($danger !== null) {
                return $danger;
            }
            // `cat .env` reads it as surely as file_read does.
            foreach (array_slice($words, 1) as $word) {
                $secret = SecretFiles::reason($word);
                if ($secret !== null) {
                    return 'it names ' . $secret;
                }
            }
        }

        return $command->opaque;
    }

    /**
     * The grants that would let this command run again unasked, one per
     * simple command in it, as they are kept.
     *
     * @return list<string>
     */
    public static function grantsFor(ShellCommand $command): array
    {
        $grants = [];
        foreach ($command->segments as $words) {
            $grants[] = self::PREFIX . self::ruleFor($words);
        }

        return array_values(array_unique($grants));
    }

    /**
     * Whether these grants let the command run without asking: every simple
     * command in it covered, and nothing in it to be asked about every time.
     *
     * @param list<string> $grants as kept: tool names and command grants together
     */
    public static function allows(ShellCommand $command, array $grants): bool
    {
        if (self::onlyOnce($command) !== null) {
            return false;
        }

        $rules = [];
        foreach ($grants as $grant) {
            if (str_starts_with($grant, self::PREFIX)) {
                $rules[] = substr($grant, strlen(self::PREFIX));
            }
        }

        foreach ($command->segments as $words) {
            $covered = false;
            foreach ($rules as $rule) {
                if (self::covers($rule, $words)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
        }

        return true;
    }

    /**
     * The grant for one simple command: its program and first argument with
     * anything after them (`make test *`), or the command exactly when its
     * first argument is an option, it has none, or its program runs others.
     *
     * @param list<string> $words
     */
    public static function ruleFor(array $words): string
    {
        $assignments = [];
        while ($words !== [] && self::isAssignment($words[0])) {
            $assignments[] = array_shift($words);
        }

        $program = basename($words[0] ?? '');
        $exact = count($words) < 2
            || str_starts_with($words[1], '-')
            || in_array($program, self::WRAPPERS, true)
            || in_array($program, self::EXACT_ONLY, true)
            || in_array($program, self::INTERPRETERS, true) && !str_contains($words[1], '/') && !str_contains($words[1], '.');

        $kept = $exact ? [...$assignments, ...$words] : [...$assignments, $words[0], $words[1]];
        $rule = implode(' ', array_map(ShellCommand::quote(...), $kept));

        return $exact ? $rule : $rule . ' *';
    }

    /**
     * Whether a rule covers these words: the same words, or, for a rule ending
     * in a bare `*`, the same words first.
     *
     * @param list<string> $words
     */
    public static function covers(string $rule, array $words): bool
    {
        $prefix = str_ends_with($rule, ' *');
        $parsed = ShellCommand::parse($prefix ? substr($rule, 0, -2) : $rule);
        if ($parsed->opaque !== null || count($parsed->segments) !== 1) {
            return false;
        }

        $ruleWords = $parsed->segments[0];

        return $prefix
            ? count($words) >= count($ruleWords) && array_slice($words, 0, count($ruleWords)) === $ruleWords
            : $words === $ruleWords;
    }

    /**
     * The command a simple command really runs: past the variables set for it
     * and the programs that only run another (`env`, `timeout 5`, `xargs -n1`).
     *
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function core(array $words): array
    {
        while ($words !== [] && self::isAssignment($words[0])) {
            array_shift($words);
        }

        while ($words !== [] && in_array(basename($words[0]), self::WRAPPERS, true)) {
            array_shift($words);
            while ($words !== [] && (str_starts_with($words[0], '-')
                || preg_match('/^\d+(\.\d+)?[smhd]?$/', $words[0]) === 1
                || self::isAssignment($words[0]))) {
                array_shift($words);
            }
        }

        return $words;
    }

    /**
     * Why running this is never granted, only allowed once, or null.
     *
     * @param list<string> $words
     */
    private static function danger(array $words, bool $piped): ?string
    {
        if ($words === []) {
            return null;
        }

        $program = basename($words[0]);
        $args = array_slice($words, 1);
        $flag = static fn (string $short, string ...$long): bool => array_filter(
            $args,
            static fn (string $arg): bool => in_array($arg, $long, true)
                || preg_match('/^-[A-Za-z]*' . $short . '[A-Za-z]*$/', $arg) === 1,
        ) !== [];

        return match (true) {
            in_array($program, ['sudo', 'su', 'doas', 'pkexec', 'runuser'], true) => 'it runs as another user',
            $program === 'rm' && $flag('[rR]', '--recursive') => 'it deletes recursively',
            in_array($program, ['shred', 'dd', 'wipefs', 'fdisk', 'parted'], true), str_starts_with($program, 'mkfs') => 'it can destroy data',
            in_array($program, ['shutdown', 'reboot', 'halt', 'poweroff'], true) => 'it stops the machine',
            in_array($program, ['chmod', 'chown', 'chgrp'], true) && $flag('R', '--recursive') => 'it changes permissions recursively',
            $program === 'eval' => 'it runs a string as a command',
            $program === 'find' && array_intersect($args, ['-delete', '-exec', '-execdir', '-ok', '-okdir']) !== [] => 'it deletes or runs commands on what it finds',
            $program === 'git' => self::gitDanger($args),
            in_array($program, ['docker', 'podman'], true) => self::dockerDanger($args),
            $piped && in_array($program, self::INTERPRETERS, true) && self::readsStdin($args) => 'it runs what is piped into it',
            default => null,
        };
    }

    /** @param list<string> $args */
    private static function gitDanger(array $args): ?string
    {
        // Past the options git takes before its command: -C dir, -c key=value.
        while ($args !== [] && str_starts_with($args[0], '-')) {
            $option = array_shift($args);
            if (in_array($option, ['-C', '-c', '--git-dir', '--work-tree', '--namespace'], true)) {
                array_shift($args);
            }
        }

        $command = array_shift($args) ?? '';
        $has = static fn (string ...$options): bool => array_filter(
            $args,
            static fn (string $arg): bool => in_array($arg, $options, true)
                || preg_match('/^-[A-Za-z]*f[A-Za-z]*$/', $arg) === 1 && in_array('-f', $options, true),
        ) !== [];

        return match (true) {
            $command === 'push' => 'it pushes to a remote',
            $command === 'reset' && $has('--hard', '--merge', '--keep') => 'it discards changes',
            $command === 'clean' && $has('-f', '--force') => 'it deletes untracked files',
            $command === 'branch' && $has('-D') => 'it deletes a branch',
            $command === 'stash' && in_array($args[0] ?? '', ['drop', 'clear'], true) => 'it drops stashed changes',
            in_array($command, ['filter-branch', 'filter-repo'], true) => 'it rewrites history',
            in_array($command, ['checkout', 'restore'], true) && array_intersect($args, ['.', '--', '-f', '--force']) !== [] => 'it discards changes',
            default => null,
        };
    }

    /** @param list<string> $args */
    private static function dockerDanger(array $args): ?string
    {
        return match (true) {
            in_array('prune', $args, true) => 'it deletes containers, images or volumes',
            in_array('down', $args, true) && array_intersect($args, ['-v', '--volumes']) !== [] => 'it deletes volumes',
            in_array('volume', $args, true) && in_array('rm', $args, true) => 'it deletes volumes',
            default => null,
        };
    }

    /** Whether an interpreter is given no script of its own, so runs its input. @param list<string> $args */
    private static function readsStdin(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg !== '-' && !str_starts_with($arg, '-')) {
                return false;
            }
            // python -m module, php -r code: a program given, not read.
            if (in_array($arg, ['-m', '-r', '-c', '-e'], true)) {
                return false;
            }
        }

        return true;
    }

    private static function isAssignment(string $word): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $word) === 1;
    }
}
