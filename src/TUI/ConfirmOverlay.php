<?php

namespace App\TUI;

use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;
use App\Permission\ConfirmChoice;
use App\Tool\FilePatchTool;
use App\Tool\FileWriteTool;
use App\Tool\ShellExecTool;

class ConfirmOverlay
{
    public function __construct(
        private readonly Terminal $terminal,
        private readonly FileWriteTool $fileWriteTool,
        private readonly FilePatchTool $filePatchTool,
        private readonly ShellExecTool $shellExecTool,
    ) {}

    /**
     * Ask, in the flow of the conversation: the box is printed where the
     * cursor is, like any other output, rather than painted at fixed screen
     * coordinates over what is already there — which left it in the middle of
     * the scrollback, the keypress echoed inside it, and no trace of what was
     * chosen. The question line is then replaced by the answer.
     */
    public function show(ToolCall $call, ToolDefinition $def): ConfirmChoice
    {
        [$cols] = $this->terminal->size();
        $width = max(20, min(76, $cols - 4));

        $diffLines = array_slice(explode("\n", $this->buildPreview($call)), 0, 18);

        echo "\n" . $this->frame($call, $def, $diffLines, $width);

        $this->terminal->rawMode();
        $choice = ConfirmChoice::Deny;

        try {
            while (true) {
                echo "\r\e[2K" . self::QUESTION;

                $key = strtolower($this->terminal->readKey());
                $picked = match ($key) {
                    'a' => ConfirmChoice::Once,
                    's' => ConfirmChoice::Session,
                    'p' => ConfirmChoice::Project,
                    // End of input refuses too: a closed terminal must not
                    // spin here, and must not allow anything either.
                    'r', "\x03", '' => ConfirmChoice::Deny,
                    default => null,
                };

                if ($picked !== null) {
                    $choice = $picked;
                    break;
                }
            }
        } finally {
            $this->terminal->restoreMode();
            // Wiped whatever the key echoed, if the terminal echoed it, along
            // with the question: the answer takes its place, and stays.
            echo "\r\e[2K" . self::verdict($choice, $call->name) . "\n";
        }

        return $choice;
    }

    private const QUESTION = '  '
        . Terminal::GREEN . '[a]' . Terminal::RESET . ' Allow once  '
        . Terminal::CYAN . '[s]' . Terminal::RESET . ' This session  '
        . Terminal::BLUE . '[p]' . Terminal::RESET . ' Always (project)  '
        . Terminal::RED . '[r]' . Terminal::RESET . ' Refuse  '
        . Terminal::BOLD . '› ' . Terminal::RESET;

    /** The line that replaces the question: what was chosen, and what happens now. */
    public static function verdict(ConfirmChoice $choice, string $tool): string
    {
        $tool = Terminal::plain($tool, singleLine: true);

        return match ($choice) {
            ConfirmChoice::Once    => Terminal::GREEN . "  ✓ Allowed once — running {$tool}…" . Terminal::RESET,
            ConfirmChoice::Session => Terminal::GREEN . "  ✓ Allowed for this session — running {$tool}…" . Terminal::RESET,
            ConfirmChoice::Project => Terminal::GREEN . "  ✓ Always allowed in this project — running {$tool}…" . Terminal::RESET,
            ConfirmChoice::Deny    => Terminal::RED . "  ✗ Refused — {$tool} does not run." . Terminal::RESET,
        };
    }

    /**
     * The box, as lines to print in sequence. Every line is cut to the width
     * and stripped: this is the screen the user approves an action from, and a
     * diff carrying its own escape sequences could repaint it to say something
     * else entirely.
     *
     * @param array<int, string> $diffLines
     */
    public function frame(ToolCall $call, ToolDefinition $def, array $diffLines, int $width): string
    {
        $inner = $width - 4;
        $yellow = Terminal::YELLOW;
        $side = $yellow . Terminal::V . Terminal::RESET;

        $row = function (string $text, string $colour) use ($inner, $side): string {
            $text = mb_strimwidth(Terminal::plain($text, singleLine: true), 0, $inner, '…');

            return '  ' . $side . ' ' . $colour . $text . Terminal::RESET
                . str_repeat(' ', max(0, $inner - mb_strwidth($text))) . ' ' . $side . "\n";
        };

        $title = ' ⚠ Confirmation — ' . Terminal::plain($call->name, singleLine: true) . ' ';
        $out = '  ' . $yellow . Terminal::TL . Terminal::H . Terminal::BOLD . $title . Terminal::RESET . $yellow
            . str_repeat(Terminal::H, max(0, $width - 3 - mb_strwidth($title))) . Terminal::TR . Terminal::RESET . "\n";

        $out .= $row($def->description, Terminal::GRAY);
        $out .= $row($this->summarizeArgs($call->arguments, $inner), Terminal::WHITE);
        $out .= $row(str_repeat('─', $inner), Terminal::GRAY);

        foreach ($diffLines as $line) {
            $plain = Terminal::plain($line, singleLine: true);
            $colour = match (true) {
                str_starts_with($plain, '+') => Terminal::GREEN,
                str_starts_with($plain, '-') => Terminal::RED,
                str_starts_with($plain, '@') => Terminal::CYAN,
                default                      => Terminal::GRAY,
            };
            $out .= $row($plain, $colour);
        }

        return $out . '  ' . $yellow . Terminal::BL . str_repeat(Terminal::H, $width - 2) . Terminal::BR . Terminal::RESET . "\n";
    }

    /**
     * Previews are delegated to the tools, which own path resolution against
     * the project root. Resolving here too can disagree with them, and show an
     * overwrite as "(new file)".
     */
    private function buildPreview(ToolCall $call): string
    {
        try {
            return $this->renderPreview($call);
        } catch (\Throwable $e) {
            // Building the preview must never be able to kill the session. The
            // tools resolve paths to build it, and a path outside the project
            // throws — which would take Sherpa down at the exact moment it was
            // asking whether to allow that call. Show the reason and let the
            // user decide; refusing is still one keypress away.
            return "(no preview available)\n" . Terminal::plain($e->getMessage());
        }
    }

    private function renderPreview(ToolCall $call): string
    {
        $args = $call->arguments;

        return match ($call->name) {
            'file_write' => $this->fileWriteTool->getDiff(
                $args['path'] ?? '',
                $args['content'] ?? '',
            ),
            'file_patch' => $this->filePatchTool->getDiff(
                $args['path'] ?? '',
                $args['search'] ?? '',
                $args['replace'] ?? '',
            ),
            'shell_exec' => $this->shellExecTool->describe(
                $args['command'] ?? '',
                $args['cwd'] ?? null,
            ),
            default => json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        };
    }

    private function summarizeArgs(array $args, int $maxLen): string
    {
        $parts = [];
        foreach ($args as $k => $v) {
            $val = is_string($v) ? $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE);
            $parts[] = $k . '=' . mb_substr(Terminal::plain($val, singleLine: true), 0, 40);
        }

        return mb_substr(Terminal::plain(implode(' ', $parts), singleLine: true), 0, $maxLen);
    }
}
