<?php

declare(strict_types=1);

namespace App\Command;

use App\Agent\MessageBag;
use App\Agent\SystemPromptBuilder;
use App\Agent\Tool\Toolbox;
use App\Mcp\CommandLine;
use App\Mcp\McpException;
use App\Mcp\McpRegistry;
use App\Mcp\PromptArguments;
use App\Mcp\ServerConfig;
use App\Project\Project;
use App\TUI\ChatPane;
use App\TUI\LineEditor;
use App\TUI\Terminal;

/**
 * /mcp: the servers of the session, and declaring them without opening
 * mcp.json.
 *
 *   /mcp                    the servers, their resources and prompts
 *   /mcp add                declare one: name, command or address — tried at once
 *   /mcp disable <name>     turn it off, keeping how it was set up
 *   /mcp enable <name>      turn it back on
 *   /mcp remove <name>      forget it
 *
 * Every change is in force at once, as /provider's are: the server's tools
 * come in or go, and the system prompt is rebuilt for its instructions.
 */
final class McpCommands
{
    public function __construct(
        private readonly McpRegistry $mcp,
        private readonly Toolbox $toolbox,
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly ChatPane $chat,
        private readonly LineEditor $lineEditor,
    ) {}

    public function handle(string $args, MessageBag $bag, Project $project): void
    {
        [$verb, $rest] = array_pad(preg_split('/\s+/', trim($args), 2) ?: [], 2, '');
        $name = trim($rest);

        try {
            match (strtolower($verb)) {
                ''        => $this->list(),
                'add'     => $this->add($bag, $project),
                'remove'  => $this->remove($name, $bag, $project),
                'enable'  => $this->enable($name, $bag, $project),
                'disable' => $this->disable($name, $bag, $project),
                default   => $this->usage(),
            };
        } catch (McpException $e) {
            echo Terminal::RED . '✗ ' . $e->getMessage() . "\n" . Terminal::RESET;
        }
    }

    private function list(): void
    {
        $report = $this->mcp->report();
        if ($report === []) {
            $this->chat->showInfo('No MCP server configured. /mcp add declares one, in ' . $this->mcp->config()->path() . '.');

            return;
        }

        echo Terminal::BOLD . "\nMCP servers:\n" . Terminal::RESET;
        foreach ($report as $line) {
            echo Terminal::YELLOW . '  • ' . Terminal::RESET . Terminal::plain($line) . "\n";
        }

        foreach ($this->mcp->resources() as $server => $resources) {
            echo Terminal::BOLD . "\nResources of {$server}" . Terminal::RESET
                . Terminal::GRAY . " (the model reads them with mcp__…__read_resource)\n" . Terminal::RESET;
            foreach ($resources as $resource) {
                echo '  ' . Terminal::plain($resource['uri'], singleLine: true)
                    . Terminal::GRAY . '  ' . Terminal::plain($resource['name'], singleLine: true) . "\n" . Terminal::RESET;
            }
        }

        $prompts = $this->mcp->promptCommands();
        if ($prompts !== []) {
            echo Terminal::BOLD . "\nPrompts" . Terminal::RESET . Terminal::GRAY . " (run as commands)\n" . Terminal::RESET;
            foreach ($prompts as $prompt) {
                echo '  ' . Terminal::YELLOW . Terminal::plain($prompt['command'], singleLine: true) . Terminal::RESET
                    . ' ' . PromptArguments::usage($prompt['arguments'])
                    . Terminal::GRAY . '  ' . Terminal::plain($prompt['description'], singleLine: true) . "\n" . Terminal::RESET;
            }
        }

        echo Terminal::GRAY . "\n  /mcp add · /mcp disable <name> · /mcp enable <name> · /mcp remove <name>"
            . ' · in ' . $this->mcp->config()->path() . "\n" . Terminal::RESET;
    }

    /**
     * Declare a server and bring it in: name, then a command or an address,
     * then what it needs to authenticate. Tried before it is saved, so a typo
     * is found now rather than at the next launch.
     */
    private function add(MessageBag $bag, Project $project): void
    {
        echo Terminal::BOLD . Terminal::CYAN . "\n  Sherpa · New MCP server\n" . Terminal::RESET;
        echo Terminal::GRAY
            . "  A command that starts it on this machine, or the http(s) address of one online:\n"
            . "    npx -y @modelcontextprotocol/server-filesystem ~/notes\n"
            . "    sh -c 'exec docker run --rm -i -v \"\$PWD:\$PWD\" ghcr.io/hbenabdallah/phpgraph serve \"\$PWD\"'\n"
            . "    https://example.com/mcp\n"
            . "  Empty line to cancel.\n\n" . Terminal::RESET;

        $name = $this->askName();
        if ($name === null) {
            $this->chat->showInfo('No MCP server added.');

            return;
        }

        $target = trim($this->lineEditor->ask('  Command or address: ', Terminal::BOLD));
        if ($target === '') {
            $this->chat->showInfo('No MCP server added.');

            return;
        }

        $previous = $this->mcp->config()->server($name);
        $server = ServerConfig::fromArray($name, preg_match('#^https?://#i', $target) === 1
            ? ['url' => $target, 'headers' => $this->askHeaders()]
            : $this->commandEntry($target));

        try {
            $line = $this->mcp->connect($server, $this->toolbox);
        } catch (McpException $e) {
            echo Terminal::YELLOW . '  ' . $e->getMessage() . "\n" . Terminal::RESET;
            $keep = strtolower(trim($this->lineEditor->ask('  Save it anyway? [y/N]: ', Terminal::BOLD)));
            if (!in_array($keep, ['y', 'yes', 'o', 'oui'], true)) {
                $this->restore($name, $previous);
                $this->chat->showInfo('No MCP server added.');

                return;
            }

            $this->mcp->config()->save($server);
            $this->saved($server->name, 'saved, not connected: it is tried again at the next launch');

            return;
        }

        $this->mcp->config()->save($server);
        $bag->system($this->promptBuilder->build($project));

        echo Terminal::GREEN . '✓ ' . Terminal::plain($line) . "\n" . Terminal::RESET;
        $this->saved($server->name, 'its tools ask before they run; [p] at the question allows one for this project');
    }

    private function remove(string $name, MessageBag $bag, Project $project): void
    {
        if (!$this->named($name, 'remove')) {
            return;
        }

        if (!$this->mcp->config()->remove($name)) {
            $this->unknown($name);

            return;
        }

        $this->mcp->disconnect($name, $this->toolbox);
        $bag->system($this->promptBuilder->build($project));
        echo Terminal::GREEN . "✓ {$name} removed" . Terminal::RESET
            . Terminal::GRAY . ' from ' . $this->mcp->config()->path() . "\n" . Terminal::RESET;
    }

    private function disable(string $name, MessageBag $bag, Project $project): void
    {
        if (!$this->named($name, 'disable')) {
            return;
        }

        if (!$this->mcp->config()->setEnabled($name, false)) {
            $this->unknown($name);

            return;
        }

        $this->mcp->disconnect($name, $this->toolbox, "{$name}: disabled");
        $bag->system($this->promptBuilder->build($project));
        echo Terminal::GREEN . "✓ {$name} disabled" . Terminal::RESET
            . Terminal::GRAY . " — its setup is kept; /mcp enable {$name} brings it back\n" . Terminal::RESET;
    }

    private function enable(string $name, MessageBag $bag, Project $project): void
    {
        if (!$this->named($name, 'enable')) {
            return;
        }

        if (!$this->mcp->config()->setEnabled($name, true)) {
            $this->unknown($name);

            return;
        }

        $server = $this->mcp->config()->server($name);
        if ($server === null) {
            $this->unknown($name);

            return;
        }

        $line = $this->mcp->connect($server, $this->toolbox);
        $bag->system($this->promptBuilder->build($project));
        echo Terminal::GREEN . '✓ ' . Terminal::plain($line) . "\n" . Terminal::RESET;
    }

    /**
     * Put back what a failed replacement took out of the session: the server
     * as it was declared, when there was one and it was on.
     */
    private function restore(string $name, ?ServerConfig $previous): void
    {
        $this->mcp->disconnect($name, $this->toolbox);
        if ($previous === null || !$previous->enabled) {
            return;
        }

        try {
            $this->mcp->connect($previous, $this->toolbox);
        } catch (McpException) {
            // It was not reachable before either; the report says so.
        }
    }

    /** A name for the new server, or null to cancel. */
    private function askName(): ?string
    {
        while (true) {
            $name = trim($this->lineEditor->ask('  Name: ', Terminal::BOLD));
            if ($name === '') {
                return null;
            }

            if (!ServerConfig::validName($name)) {
                echo Terminal::YELLOW . "  A short word: letters, digits, - or _. It prefixes the tools: mcp__<name>__…\n" . Terminal::RESET;
                continue;
            }

            if ($this->mcp->config()->server($name) === null) {
                return $name;
            }

            $replace = strtolower(trim($this->lineEditor->ask("  \"{$name}\" exists already. Replace it? [y/N]: ", Terminal::BOLD)));
            if (in_array($replace, ['y', 'yes', 'o', 'oui'], true)) {
                return $name;
            }
        }
    }

    /**
     * The command cut into its words, and the variables it needs — values
     * typed hidden, since that is where a server's tokens go.
     *
     * @return array<string, mixed>
     */
    private function commandEntry(string $line): array
    {
        $words = CommandLine::split($line);
        $env = [];

        echo Terminal::GRAY . "  Environment variables it needs, if any — a token, say. Enter to finish.\n" . Terminal::RESET;
        while (true) {
            $variable = trim($this->lineEditor->ask('  Variable: ', Terminal::BOLD));
            if ($variable === '') {
                break;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $variable) !== 1) {
                echo Terminal::YELLOW . "  A variable name: letters, digits and _.\n" . Terminal::RESET;
                continue;
            }
            $env[$variable] = $this->lineEditor->askSecret("  Value of {$variable} (hidden): ", Terminal::BOLD);
        }

        return ['command' => $words[0] ?? '', 'args' => array_slice($words, 1), 'env' => $env];
    }

    /** @return array<string, string> */
    private function askHeaders(): array
    {
        $authorization = $this->lineEditor->askSecret('  Authorization header, e.g. "Bearer …" (hidden; Enter for none): ', Terminal::BOLD);

        return $authorization === '' ? [] : ['Authorization' => $authorization];
    }

    private function saved(string $name, string $note): void
    {
        echo Terminal::GREEN . "✓ {$name} saved" . Terminal::RESET
            . Terminal::GRAY . ' in ' . $this->mcp->config()->path() . " (readable by you only) — {$note}\n" . Terminal::RESET;
    }

    private function named(string $name, string $verb): bool
    {
        if ($name !== '') {
            return true;
        }

        echo Terminal::YELLOW . "Which one? /mcp {$verb} <name> — /mcp lists them.\n" . Terminal::RESET;

        return false;
    }

    private function unknown(string $name): void
    {
        echo Terminal::RED . "No MCP server named {$name}." . Terminal::RESET
            . Terminal::GRAY . " /mcp lists them.\n" . Terminal::RESET;
    }

    private function usage(): void
    {
        echo Terminal::YELLOW . "/mcp · /mcp add · /mcp disable <name> · /mcp enable <name> · /mcp remove <name>\n" . Terminal::RESET;
    }
}
