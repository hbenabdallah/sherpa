<?php

declare(strict_types=1);

namespace App\TUI;

use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;
use App\Permission\Approver;
use App\Permission\ConfirmChoice;
use App\Permission\Offer;
use App\Tool\FilePatchTool;
use App\Tool\FileWriteTool;
use App\Tool\ShellExecTool;
use Throwable;

class ConfirmOverlay implements Approver
{
    /** Rows of preview the box holds; past them, it says how many it hid. */
    private const int PREVIEW_ROWS = 24;

    public function __construct(
        private readonly Terminal $terminal,
        private readonly FileWriteTool $fileWriteTool,
        private readonly FilePatchTool $filePatchTool,
        private readonly ShellExecTool $shellExecTool,
        private readonly MarkdownRenderer $markdown = new MarkdownRenderer(),
    ) {}

    public function confirm(ToolCall $call, ToolDefinition $def, Offer $offer): ConfirmChoice
    {
        return $this->show($call, $def, $offer);
    }

    /**
     * The plan in full, then one key: y carries it out, n keeps planning. In
     * a pipe nobody can press either.
     */
    public function approvePlan(string $plan): ?bool
    {
        echo "\n" . Terminal::BOLD . Terminal::LIME . 'Plan' . Terminal::RESET . "\n" . $this->markdown->render(trim($plan)) . "\n\n";

        if (!function_exists('posix_isatty') || !posix_isatty(STDIN)) {
            return null;
        }

        $approved = $this->askAboutPlan();
        echo "\r\e[2K" . ($approved
            ? Terminal::GREEN . '  ✓ Plan approved: carrying it out.'
            : Terminal::GRAY . '  Plan not approved: say what to change.') . Terminal::RESET . "\n";

        return $approved;
    }

    private function askAboutPlan(): bool
    {
        $this->terminal->rawMode();
        // A key typed while the plan was being written is no answer to it.
        $this->terminal->discardPendingInput();

        try {
            while (true) {
                echo "\r\e[2K  Carry out this plan? "
                    . Terminal::GREEN . '[y]' . Terminal::RESET . ' yes, go  '
                    . Terminal::YELLOW . '[n]' . Terminal::RESET . ' no, keep planning ';

                $key = strtolower($this->terminal->readKey());
                if (in_array($key, ['y', "\r", "\n"], true)) {
                    return true;
                }
                if (in_array($key, ['n', "\e", ''], true)) {
                    return false;
                }
            }
        } finally {
            $this->terminal->restoreMode();
        }
    }

    /**
     * Ask, in the flow of the conversation: the box is printed where the
     * cursor is, like any other output, rather than painted at fixed screen
     * coordinates over what is already there — which left it in the middle of
     * the scrollback, the keypress echoed inside it, and no trace of what was
     * chosen. The question line is then replaced by the answer.
     */
    public function show(ToolCall $call, ToolDefinition $def, Offer $offer = new Offer()): ConfirmChoice
    {
        [$cols] = $this->terminal->size();
        $width = max(20, min(76, $cols - 4));

        $diffLines = explode("\n", $this->buildPreview($call));

        echo "\n" . $this->frame($call, $def, $diffLines, $width, $offer);

        $this->terminal->rawMode();
        // A key typed while the model was working is not an answer to a
        // question it had not seen yet — least of all "a" for allow.
        $this->terminal->discardPendingInput();
        $choice = ConfirmChoice::Deny;

        try {
            while (true) {
                echo "\r\e[2K" . ($offer->standing() ? self::QUESTION : self::QUESTION_ONCE);

                $key = strtolower($this->terminal->readKey());
                $picked = match ($key) {
                    'a' => ConfirmChoice::Once,
                    // Not offered for what is only ever allowed once.
                    's' => $offer->standing() ? ConfirmChoice::Session : null,
                    'p' => $offer->standing() ? ConfirmChoice::Project : null,
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
            echo "\r\e[2K" . self::verdict($choice, $call->name, $offer) . "\n";
        }

        return $choice;
    }

    private const string QUESTION = '  '
        . Terminal::GREEN . '[a]' . Terminal::RESET . ' Allow once  '
        . Terminal::CYAN . '[s]' . Terminal::RESET . ' This session  '
        . Terminal::BLUE . '[p]' . Terminal::RESET . ' Always (project)  '
        . Terminal::RED . '[r]' . Terminal::RESET . ' Refuse  '
        . Terminal::BOLD . '› ' . Terminal::RESET;

    private const string QUESTION_ONCE = '  '
        . Terminal::GREEN . '[a]' . Terminal::RESET . ' Allow once  '
        . Terminal::RED . '[r]' . Terminal::RESET . ' Refuse  '
        . Terminal::BOLD . '› ' . Terminal::RESET;

    /** The line that replaces the question: what was chosen, and what happens now. */
    public static function verdict(ConfirmChoice $choice, string $tool, Offer $offer = new Offer()): string
    {
        $tool = Terminal::plain($tool, singleLine: true);
        // What the grant names, when it is not the whole tool.
        $what = $offer->grants === [] ? '' : ': ' . Terminal::plain(implode(', ', $offer->commands()), singleLine: true);

        return match ($choice) {
            ConfirmChoice::Once    => Terminal::GREEN . "  ✓ Allowed once — running {$tool}…" . Terminal::RESET,
            ConfirmChoice::Session => Terminal::GREEN . "  ✓ Allowed for this session{$what} — running {$tool}…" . Terminal::RESET,
            ConfirmChoice::Project => Terminal::GREEN . "  ✓ Always allowed in this project{$what} — running {$tool}…" . Terminal::RESET,
            ConfirmChoice::Deny    => Terminal::RED . "  ✗ Refused — {$tool} does not run." . Terminal::RESET,
        };
    }

    /**
     * The box, as lines to print in sequence. Every line is stripped: this is
     * the screen the user approves an action from, and a diff carrying its own
     * escape sequences could repaint it to say something else entirely.
     *
     * The preview is wrapped, never cut at the width — the end of a long
     * command, after a run of spaces, was approved without being seen — and
     * what does not fit is counted out loud rather than dropped.
     *
     * Under the arguments, what a longer answer would grant — a command
     * rather than the tool — or why only "once" is offered.
     *
     * @param array<int, string> $diffLines
     */
    public function frame(ToolCall $call, ToolDefinition $def, array $diffLines, int $width, Offer $offer = new Offer()): string
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
        // Wrapped, not cut: this is what a grant would cover from now on.
        $note = match (true) {
            !$offer->standing() => ["⚠ Asked every time: {$offer->onlyOnce}", Terminal::RED],
            $offer->grants !== [] => ['[s]/[p] would allow: ' . implode(', ', $offer->commands()), Terminal::CYAN],
            default => null,
        };
        foreach ($note === null ? [] : self::wrap(Terminal::plain($note[0], singleLine: true), $inner) as $piece) {
            $out .= $row($piece, $note[1]);
        }
        $out .= $row(str_repeat('─', $inner), Terminal::GRAY);

        $shown = 0;
        foreach ($diffLines as $index => $line) {
            $plain = str_replace("\t", '    ', Terminal::plain($line, singleLine: true));
            $colour = match (true) {
                str_starts_with($plain, '+') => Terminal::GREEN,
                str_starts_with($plain, '-') => Terminal::RED,
                str_starts_with($plain, '@') => Terminal::CYAN,
                default                      => Terminal::GRAY,
            };

            $pieces = self::wrap($plain, $inner);
            if ($shown + count($pieces) > self::PREVIEW_ROWS - 1 && $index < count($diffLines) - 1
                || $shown + count($pieces) > self::PREVIEW_ROWS) {
                $hidden = count($diffLines) - $index;
                $out .= $row("⚠ {$hidden} more line" . ($hidden > 1 ? 's' : '') . ' not shown — refuse unless you have seen enough', Terminal::RED);
                break;
            }

            foreach ($pieces as $piece) {
                $out .= $row($piece, $colour);
                $shown++;
            }
        }

        return $out . '  ' . $yellow . Terminal::BL . str_repeat(Terminal::H, $width - 2) . Terminal::BR . Terminal::RESET . "\n";
    }

    /**
     * A line in pieces that fit the width, measured as the terminal draws them.
     *
     * @return array<int, string>
     */
    private static function wrap(string $line, int $width): array
    {
        $pieces = [];
        $piece = '';

        foreach (mb_str_split($line) as $char) {
            if ($piece !== '' && mb_strwidth($piece . $char) > $width) {
                $pieces[] = $piece;
                $piece = '';
            }
            $piece .= $char;
        }

        return [...$pieces, $piece];
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
        } catch (Throwable $e) {
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
            ) . (filter_var($args['background'] ?? false, FILTER_VALIDATE_BOOLEAN)
                ? "\n(left running in the background: /jobs lists it, and it stops when Sherpa exits)"
                : ''),
            default => json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        };
    }

    /** @param array<string, mixed> $args */
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
