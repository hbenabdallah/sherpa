<?php

declare(strict_types=1);

namespace App\Acp;

use App\Agent\MessageBag;
use App\Command\SlashCommands;
use App\Mcp\McpException;
use App\Mcp\McpRegistry;
use App\Mcp\PromptArguments;
use App\Project\Project;
use App\Skills\Skill;
use App\Skills\SkillRegistry;
use App\TUI\Terminal;

/**
 * The terminal's "/" in an editor: the commands that ask nothing run as they
 * do there, their output the reply; a skill or an MCP prompt becomes the
 * message the model gets. A command that needs the keyboard cannot run — the
 * editor holds stdin — and says where its job is done instead.
 */
final readonly class EditorCommands
{
    /** The terminal's commands that run unchanged: none of them reads a key. */
    private const array RUN = ['compact', 'context', 'memory', 'remember', 'forget', 'todo', 'jobs', 'docs', 'permissions', 'skills', 'tools'];

    /** Run with no argument only: theirs open questions in the terminal. */
    private const array BARE = ['mcp', 'project'];

    private const array ELSEWHERE = [
        'model'    => 'The model is chosen in the menu beside the prompt box.',
        'backend'  => 'Moving between an online and a local model is done in a terminal: run sherpa there, then /backend. The editor follows once it starts Sherpa again.',
        'provider' => 'Providers are added and switched in a terminal: run sherpa there, then /provider. The editor follows once it starts Sherpa again.',
        'reset'    => 'Start a new thread in the editor: it is an empty conversation.',
        'sessions' => 'The editor\'s history lists the conversations it had; one from the terminal is picked up there, with /resume.',
        'resume'   => 'The editor\'s history lists the conversations it had; one from the terminal is picked up there, with /resume.',
        'copy'     => 'Select the reply in the editor to copy it.',
        'exit'     => 'Close the thread in the editor.',
        'quit'     => 'Close the thread in the editor.',
    ];

    public function __construct(
        private SlashCommands $commands,
        private SkillRegistry $skills,
        private McpRegistry $mcp,
    ) {}

    /**
     * What the editor offers under "/", as ACP's available commands: named
     * without the slash, which the editor adds.
     *
     * @return list<array<string, mixed>>
     */
    public function available(): array
    {
        $commands = [['name' => 'help', 'description' => 'The commands Sherpa runs here']];

        foreach (SlashCommands::catalogue() as $entry) {
            $name = substr($entry['name'], 1);
            if (in_array($name, ['plan', 'auto'], true) || in_array($name, self::RUN, true)) {
                $commands[] = self::command($name, $entry['description'], $entry['hint']);
            } elseif (in_array($name, self::BARE, true)) {
                $commands[] = self::command($name, $entry['description'], '');
            }
        }

        foreach ($this->skills->all() as $skill) {
            if (!$this->isCommand($skill->name)) {
                $commands[] = self::command($skill->name, 'skill · ' . $skill->description, '[request]');
            }
        }

        foreach ($this->mcp->promptCommands() as $prompt) {
            $commands[] = self::command(substr($prompt['command'], 1), 'MCP prompt · ' . $prompt['description'], PromptArguments::usage($prompt['arguments']));
        }

        return $commands;
    }

    /**
     * A prompt that starts with "/": its reply, when it is a command, or the
     * message for the model, when it is a skill or an MCP prompt. Anything
     * else — "/etc/hosts is wrong" — is a message as typed.
     *
     * @return array{reply: string}|array{output: string}|array{prompt: string}|array{plan: bool|null}|array{auto: bool|null}
     *               reply: prose; output: what a command printed; plan, auto: on, off, or null to switch
     */
    public function resolve(string $text, MessageBag $bag, Project $project): array
    {
        $text = trim($text);
        [$word, $rest] = array_pad(explode(' ', $text, 2), 2, '');
        $name = substr($word, 1);
        $rest = trim($rest);

        if ($name === 'help') {
            return ['reply' => $this->help()];
        }

        if ($name === 'plan') {
            return ['plan' => self::onOff($rest)];
        }

        if ($name === 'auto') {
            return ['auto' => self::onOff($rest)];
        }

        if (in_array($name, self::RUN, true) || (in_array($name, self::BARE, true) && $rest === '')) {
            return ['output' => $this->run($text, $bag, $project)];
        }

        if (in_array($name, self::BARE, true)) {
            return ['reply' => "/{$name} {$rest} asks its questions in a terminal: run sherpa there. Here, /{$name} alone shows what there is."];
        }

        if (isset(self::ELSEWHERE[$name])) {
            return ['reply' => self::ELSEWHERE[$name]];
        }

        $skill = $this->isCommand($name) ? null : $this->skills->get($name);
        if ($skill instanceof Skill) {
            return ['prompt' => $skill->applyTo($rest)];
        }

        try {
            $expanded = $this->mcp->expandPrompt($text);
        } catch (McpException $e) {
            return ['reply' => $e->getMessage()];
        }

        if ($expanded === '') {
            return ['reply' => 'The server returned an empty prompt.'];
        }

        return ['prompt' => $expanded ?? $text];
    }

    /** The command as the terminal runs it, what it prints taken as the reply. */
    private function run(string $text, MessageBag $bag, Project $project): string
    {
        ob_start();

        try {
            $this->commands->handle($text, $bag, $project);
        } finally {
            $printed = trim(Terminal::plain((string) ob_get_clean()));
        }

        return $printed === '' ? 'Done.' : $printed;
    }

    private function help(): string
    {
        $lines = [];
        foreach ($this->available() as $command) {
            $hint = $command['input']['hint'] ?? '';
            $lines[] = '- `/' . $command['name'] . ($hint !== '' ? " {$hint}" : '') . '` — ' . $command['description'];
        }

        return implode("\n", $lines) . "\n\nThe model and plan mode are in the menus beside the prompt box.";
    }

    /** One of Sherpa's own commands, which a skill of that name does not shadow. */
    private function isCommand(string $name): bool
    {
        if ($name === 'help' || isset(self::ELSEWHERE[$name])) {
            return true;
        }

        return array_any(SlashCommands::catalogue(), fn(array $entry): bool => $entry['name'] === '/' . $name);
    }

    /** @return array<string, mixed> */
    private static function command(string $name, string $description, string $hint): array
    {
        return ['name' => $name, 'description' => $description] + ($hint === '' ? [] : ['input' => ['hint' => $hint]]);
    }

    /** "on", "off", or null to switch. */
    private static function onOff(string $argument): ?bool
    {
        return match (strtolower($argument)) {
            'on'    => true,
            'off'   => false,
            default => null,
        };
    }
}
