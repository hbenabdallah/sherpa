<?php

declare(strict_types=1);

namespace App\Command;

use App\TUI\Terminal;
use App\Update\Releases;
use App\Update\SelfUpdater;
use App\Update\UpdateFailed;
use App\Version;
use Phar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `sherpa update [version]`: the latest release, or the one named, in place
 * of this executable. What the start-up notice points at; asked for, never
 * done behind the user's back — a tool that changes under a session, or on
 * the say-so of whoever controls the release, is not one to trust with code.
 */
#[AsCommand(name: 'update', description: 'Install the latest Sherpa, or the version named', help: <<<'TXT'
Downloads the executable for this machine from the GitHub release, checks it
against the release's SHA-256, and puts it in place of this one. Nothing is
touched when anything fails.

From the source, update with git pull instead.
TXT)]
final class UpdateCommand extends Command
{
    /** Asked for, so worth waiting for longer than the start-up notice does. */
    private const float LATEST_TIMEOUT = 15.0;

    private const int NOTES_LINES = 40;

    public function __construct(
        private readonly Releases $releases,
        private readonly SelfUpdater $updater,
        // The executable to replace and the version it is: found at run time,
        // given by the tests.
        private readonly ?string $executable = null,
        private readonly ?string $current = null,
        private readonly ?string $platform = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::OPTIONAL, 'A release to install instead of the latest, to go back to one: 1.0.1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $executable = $this->executable ?? (Phar::running(false) ?: null);
        if ($executable === null) {
            return $this->fail($output, 'Sherpa runs from its source here: update it with git pull.');
        }

        $platform = $this->platform ?? Releases::platform();
        if ($platform === null) {
            return $this->fail($output, 'There is no Sherpa executable for this machine: run it from the source.');
        }

        $current = $this->current ?? Version::current();
        $asked = ltrim(trim((string) $input->getArgument('version')), 'v');

        if ($asked !== '' && preg_match(Releases::VERSION, $asked) !== 1) {
            return $this->fail($output, "Not a release number: {$asked}. Like 1.0.1.");
        }

        $version = $asked !== '' ? $asked : $this->releases->latest(self::LATEST_TIMEOUT);
        if ($version === null) {
            return $this->fail($output, 'Could not tell the latest release from ' . Releases::URL . '. Name one: sherpa update 1.0.1.');
        }

        if ($version === $current) {
            $output->writeln("<info>✓</info> Sherpa {$current} is " . ($asked !== '' ? 'the version installed' : 'the latest') . '.');

            return Command::SUCCESS;
        }

        $output->writeln("Downloading Sherpa {$version} for {$platform}…");

        // This process reads its classes from the archive it runs from, as it
        // needs them — and once replaced, that file is the new release, laid
        // out differently: a class loaded then comes from the wrong offsets,
        // source printed and a fatal error after it. So all that is said
        // afterwards is written out before, while the old file is still there,
        // and only copied to the screen once the new one is in place.
        $said = [];
        $formatter = $output->getFormatter();

        try {
            $this->updater->install($version, $platform, $executable, function (?string $notes) use (&$said, $formatter, $version, $executable, $current): void {
                $lines = ["<info>✓</info> Sherpa {$version} installed in " . OutputFormatter::escape($executable) . " (was {$current})."];
                if ($notes !== null) {
                    $lines = [...$lines, '', ...self::shown($notes)];
                }
                $said = array_map(fn(string $line): string => $formatter->format($line) . "\n", $lines);
            });
        } catch (UpdateFailed $e) {
            return $this->fail($output, $e->getMessage() . '. Nothing was changed.');
        }

        $output->write(implode('', $said), false, OutputInterface::OUTPUT_RAW);

        // Nothing is left to do: stop here, before the console's own teardown
        // loads anything else.
        if ($executable === Phar::running(false)) {
            exit(Command::SUCCESS);
        }

        return Command::SUCCESS;
    }

    /**
     * The notes as plain lines: they come from the network, so no escape
     * sequence nor console tag of theirs gets through, and a screenful at most.
     *
     * @return list<string>
     */
    private static function shown(string $notes): array
    {
        $lines = array_map(
            fn(string $line): string => OutputFormatter::escape(Terminal::plain($line, singleLine: true)),
            explode("\n", trim($notes)),
        );

        return count($lines) > self::NOTES_LINES
            ? [...array_slice($lines, 0, self::NOTES_LINES), '…the rest at ' . Releases::URL]
            : $lines;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln('<error>' . OutputFormatter::escape($message) . '</error>');

        return Command::FAILURE;
    }
}
