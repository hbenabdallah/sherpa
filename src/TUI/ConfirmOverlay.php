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

    public function show(ToolCall $call, ToolDefinition $def): ConfirmChoice
    {
        [$cols, $rows] = $this->terminal->size();
        $width = min(72, $cols - 4);
        $x = (int) (($cols - $width) / 2);
        $y = max(2, (int) ($rows / 4));

        $diff = $this->buildPreview($call);
        $diffLines = array_slice(explode("\n", $diff), 0, 18);
        $height = count($diffLines) + 8;

        $this->terminal->rawMode();
        $this->terminal->hideCursor();

        try {
            $this->renderOverlay($x, $y, $width, $height, $call, $def, $diffLines);

            while (true) {
                $key = $this->terminal->readKey();

                switch (strtolower($key)) {
                    case 'a':
                        return ConfirmChoice::Once;
                    case 's':
                        return ConfirmChoice::Session;
                    case 'p':
                        return ConfirmChoice::Project;
                    case 'r':
                    case "\x03":
                        return ConfirmChoice::Deny;
                    default:
                        // Redraw on unknown key
                        $this->renderOverlay($x, $y, $width, $height, $call, $def, $diffLines);
                }
            }
        } finally {
            $this->terminal->showCursor();
            $this->terminal->restoreMode();
            // Park the cursor under the box. It is left sitting on the footer
            // otherwise, so the tool result the caller prints next lands inside
            // the overlay, on top of the very diff that was just approved.
            $this->terminal->moveTo(min($rows, $y + $height), 1);
            echo "\n";
        }
    }

    private function renderOverlay(
        int $x, int $y, int $width, int $height,
        ToolCall $call, ToolDefinition $def, array $diffLines
    ): void {
        $title = "⚠ Confirmation — {$call->name}";
        $this->terminal->box($x, $y, $width, $height, $title, Terminal::YELLOW);

        // Description
        $this->terminal->moveTo($y + 2, $x + 2);
        $desc = mb_substr(Terminal::plain($def->description, singleLine: true), 0, $width - 4);
        echo Terminal::GRAY . $desc . Terminal::RESET;

        // Args summary
        $this->terminal->moveTo($y + 3, $x + 2);
        $argSummary = $this->summarizeArgs($call->arguments, $width - 4);
        echo Terminal::WHITE . $argSummary . Terminal::RESET;

        // Separator
        $this->terminal->moveTo($y + 4, $x + 2);
        echo Terminal::GRAY . str_repeat('─', $width - 4) . Terminal::RESET;

        // Diff lines. Stripped, not merely truncated: this is the screen the
        // user approves an action from, and a diff carrying its own escape
        // sequences could repaint the footer to say something else entirely.
        foreach ($diffLines as $i => $line) {
            $this->terminal->moveTo($y + 5 + $i, $x + 2);
            $line = mb_substr(Terminal::plain($line, singleLine: true), 0, $width - 4);

            if (str_starts_with($line, '+')) {
                echo Terminal::GREEN . $line . Terminal::RESET;
            } elseif (str_starts_with($line, '-')) {
                echo Terminal::RED . $line . Terminal::RESET;
            } elseif (str_starts_with($line, '@')) {
                echo Terminal::CYAN . $line . Terminal::RESET;
            } else {
                echo Terminal::GRAY . $line . Terminal::RESET;
            }
        }

        // Footer
        $this->terminal->moveTo($y + $height - 2, $x + 2);
        // "[p] Projet" persists to projects.yaml and outlives the session, so it
        // is labelled as the standing grant it is rather than as a third shade
        // of the same thing.
        echo Terminal::BG_DARK . ' '
            . Terminal::GREEN . '[a] Une fois  '
            . Terminal::CYAN  . '[s] Session  '
            . Terminal::BLUE  . '[p] Toujours (projet)  '
            . Terminal::RED   . '[r] Refuser'
            . ' ' . Terminal::RESET;
    }

    /**
     * Previews are delegated to the tools themselves. They own path resolution
     * against the project root; resolving here independently is how this overlay
     * used to render every existing file as "(new file)" — the user would be
     * approving an overwrite believing it was a creation.
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
            return "(aperçu indisponible)\n" . Terminal::plain($e->getMessage());
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
