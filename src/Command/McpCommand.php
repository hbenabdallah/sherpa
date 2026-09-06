<?php

declare(strict_types=1);

namespace App\Command;

use App\Mcp\McpClient;
use App\Mcp\McpConfig;
use App\Mcp\McpException;
use App\Mcp\ServerConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `sherpa mcp …`: the MCP servers, declared from the shell — for a script, an
 * install guide, or someone who would rather not open a session to do it.
 * The same file as /mcp writes, the same checks.
 */
#[AsCommand(name: 'mcp', description: 'Declare the MCP servers Sherpa connects to')]
final class McpCommand extends Command
{
    /** Long enough for `npx -y` to fetch a package; shorter than a session's. */
    private const CHECK_TIMEOUT = 60.0;

    public function __construct(private readonly McpConfig $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'list, add, remove, enable or disable', 'list')
            ->addArgument('name', InputArgument::OPTIONAL, 'The server: it prefixes its tools, mcp__<name>__…')
            ->addArgument('server-command', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'For add: the command that starts it, after --')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'For add: the http(s) address of a server online, instead of a command')
            ->addOption('header', 'H', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'For add --url: a header sent with every request, "Name: value"')
            // Not --env/-e: the console takes those for the kernel's environment.
            ->addOption('var', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'For add: a variable the command gets, KEY=value')
            ->addOption('no-check', null, InputOption::VALUE_NONE, 'For add: save without starting the server to try it')
            ->setHelp(<<<'HELP'
                <info>sherpa mcp list</info>
                <info>sherpa mcp add</info> <name> [--var KEY=value]... -- <command> [arguments...]
                <info>sherpa mcp add</info> <name> --url https://… [-H "Authorization: Bearer …"]
                <info>sherpa mcp disable</info>|<info>enable</info>|<info>remove</info> <name>

                A server added is started once to check it answers, and saved only if it
                does; --no-check saves it as it is. Values given with --var and -H end up in
                your shell history: for a token, /mcp add in a session asks for it hidden.

                Servers are kept in ~/.config/sherpa/mcp.json, readable by you only.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');

        try {
            return match ((string) $input->getArgument('action')) {
                'list'    => $this->list($output),
                'add'     => $this->add($name, $input, $output),
                'remove'  => $this->remove($name, $output),
                'enable'  => $this->toggle($name, true, $output),
                'disable' => $this->toggle($name, false, $output),
                default   => $this->fail($output, 'Unknown action "' . $input->getArgument('action') . '": list, add, remove, enable or disable.'),
            };
        } catch (McpException $e) {
            return $this->fail($output, $e->getMessage());
        }
    }

    private function list(OutputInterface $output): int
    {
        $servers = $this->config->servers();
        if ($servers === []) {
            $output->writeln('No MCP server declared. <info>sherpa mcp add</info> declares one.');

            return self::SUCCESS;
        }

        foreach ($servers as $server) {
            $target = $server->isHttp()
                ? OutputFormatter::escape($server->url) . $this->hidden(' headers', array_keys($server->headers))
                : OutputFormatter::escape(implode(' ', array_map($this->quoted(...), [$server->command, ...$server->args])))
                    . $this->hidden(' env', array_keys($server->env));

            $output->writeln(sprintf(
                '%s <info>%s</info>  %s',
                $server->enabled ? '●' : '○',
                $server->name,
                $target . ($server->enabled ? '' : '  <comment>(disabled)</comment>'),
            ));
        }
        $output->writeln('<comment>' . $this->config->path() . '</comment>');

        return self::SUCCESS;
    }

    private function add(string $name, InputInterface $input, OutputInterface $output): int
    {
        if ($name === '') {
            return $this->fail($output, 'A name is needed: sherpa mcp add <name> -- <command>, or --url.');
        }

        $url = trim((string) $input->getOption('url'));
        $command = array_values(array_map(strval(...), $input->getArgument('server-command')));

        if ($url !== '' && $command !== []) {
            return $this->fail($output, 'A command or --url, not both.');
        }
        if ($url === '' && $command === []) {
            return $this->fail($output, 'Nothing to start: sherpa mcp add <name> -- <command>, or --url https://….');
        }

        $server = ServerConfig::fromArray($name, $url !== ''
            ? ['url' => $url, 'headers' => $this->pairs($input->getOption('header'), ':', 'header')]
            : ['command' => $command[0], 'args' => array_slice($command, 1), 'env' => $this->pairs($input->getOption('var'), '=', 'variable')]);

        if (!$input->getOption('no-check')) {
            $client = McpClient::for($server, callTimeout: self::CHECK_TIMEOUT);
            try {
                $tools = count($client->listTools());
            } catch (McpException $e) {
                $output->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');
                $output->writeln('Not saved. <info>--no-check</info> saves it without trying — for a server that only starts inside a project, say.');

                return self::FAILURE;
            } finally {
                $client->close();
            }
            $output->writeln("<info>✓</info> {$name} answers: {$tools} tool" . ($tools === 1 ? '' : 's'));
        }

        $replaced = $this->config->server($name) !== null;
        $this->config->save($server);
        $output->writeln("<info>✓</info> {$name} " . ($replaced ? 'replaced' : 'saved') . ' in ' . $this->config->path()
            . ' — in the next session; in one already open, /mcp enable ' . $name);

        return self::SUCCESS;
    }

    private function remove(string $name, OutputInterface $output): int
    {
        if (!$this->config->remove($name)) {
            return $this->fail($output, $name === '' ? 'Which one? sherpa mcp remove <name>' : "No MCP server named {$name}.");
        }

        $output->writeln("<info>✓</info> {$name} removed from " . $this->config->path());

        return self::SUCCESS;
    }

    private function toggle(string $name, bool $enabled, OutputInterface $output): int
    {
        if (!$this->config->setEnabled($name, $enabled)) {
            return $this->fail($output, $name === '' ? 'Which one? sherpa mcp ' . ($enabled ? 'enable' : 'disable') . ' <name>' : "No MCP server named {$name}.");
        }

        $output->writeln("<info>✓</info> {$name} " . ($enabled ? 'enabled' : 'disabled, its setup kept'));

        return self::SUCCESS;
    }

    /**
     * "Authorization: Bearer x" or "TOKEN=x", as a map.
     *
     * @param array<int, string> $items
     *
     * @return array<string, string>
     *
     * @throws McpException
     */
    private function pairs(array $items, string $separator, string $what): array
    {
        $pairs = [];
        foreach ($items as $item) {
            [$key, $value] = array_pad(explode($separator, (string) $item, 2), 2, null);
            $key = trim((string) $key);
            if ($key === '' || $value === null) {
                throw new McpException("A {$what} is written " . ($separator === ':' ? '"Name: value"' : 'KEY=value') . ": {$item}");
            }
            $pairs[$key] = $separator === ':' ? trim($value) : $value;
        }

        return $pairs;
    }

    /** The names only: values are where the tokens are. @param array<int, string> $names */
    private function hidden(string $label, array $names): string
    {
        return $names === [] ? '' : "  <comment>{$label}: " . OutputFormatter::escape(implode(', ', $names)) . '</comment>';
    }

    private function quoted(string $word): string
    {
        return $word !== '' && preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $word) === 1 ? $word : escapeshellarg($word);
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln('<error>' . OutputFormatter::escape($message) . '</error>');

        return self::FAILURE;
    }
}
