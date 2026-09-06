<?php
// Terminal escape handling: what gets stripped from untrusted text, and the
// display behaviours that depend on it.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;
use App\Agent\Tool\Permission;
use App\TUI\ChatPane;
use App\TUI\ConfirmOverlay;
use App\TUI\MarkdownRenderer;
use App\TUI\Terminal;
use App\Tool\FilePatchTool;
use App\Tool\FileWriteTool;
use App\Tool\ShellExecTool;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", substr($detail, 0, 240), "\n";
    }
}

/** Run a callable and return everything it echoed. */
function captured(callable $fn): string
{
    ob_start();
    $fn();

    return (string) ob_get_clean();
}

// ---- Terminal::plain -------------------------------------------------------
check('plain text is untouched', Terminal::plain('hello world') === 'hello world');
check('cursor-up is removed', Terminal::plain("ab\e[Acd") === 'abcd');
check('cursor-left is removed', Terminal::plain("ab\e[Dcd") === 'abcd');
check('colour codes are removed', Terminal::plain("\e[1m\e[32mx\e[0m") === 'x');
check('screen clear is removed', Terminal::plain("\e[2J\e[Hx") === 'x');
check('parametrised CSI is removed', Terminal::plain("\e[38;5;196mred") === 'red');
check('private-mode CSI is removed', Terminal::plain("\e[?25lx") === 'x');
check('OSC window title is removed', Terminal::plain("\e]0;pwned\x07x") === 'x');
check('OSC terminated by ST is removed', Terminal::plain("\e]0;pwned\e\\x") === 'x');
check('full terminal reset is removed', Terminal::plain("\ecx") === 'x');
check('save-cursor is removed', Terminal::plain("\e7x") === 'x');
check('charset selection is removed', Terminal::plain("\e(Bx") === 'x', Terminal::plain("\e(Bx"));
check('DCS payload does not spill onto the screen', Terminal::plain("\ePpayload\e\\x") === 'x', Terminal::plain("\ePpayload\e\\x"));
// A terminal consumes the byte after ESC as part of the sequence; so do we.
check('an escape-led pair is taken whole', Terminal::plain("a\eb") === 'a', Terminal::plain("a\eb"));
check('a trailing lone escape is removed', Terminal::plain("ab\e") === 'ab', bin2hex(Terminal::plain("ab\e")));
check('carriage return is removed', Terminal::plain("safe\rroot@host:~# ") === 'saferoot@host:~# ');
check('backspace is removed', Terminal::plain("ab\x08c") === 'abc');
check('DEL is removed', Terminal::plain("ab\x7fc") === 'abc');
check('NUL is removed', Terminal::plain("a\x00b") === 'ab');

check('newlines survive by default', Terminal::plain("a\nb") === "a\nb");
check('newlines collapse in single-line mode', Terminal::plain("a\nb", singleLine: true) === 'a b');
check('tabs survive', Terminal::plain("a\tb") === "a\tb");

check('accents survive', Terminal::plain('réponse élidée') === 'réponse élidée');
check('emoji survive', Terminal::plain('🐳 docker') === '🐳 docker');
check('box drawing survives', Terminal::plain('┌──┐') === '┌──┐');
check(
    'a multibyte character is not split by stripping',
    mb_check_encoding(Terminal::plain("é\e[Aé", singleLine: true), 'UTF-8'),
);

// The exact bytes recovered from a live pty session: two arrow keys typed at
// the prompt, echoed back into the transcript.
$fromRealSession = "\x1b[37mab\x1b[A\x1b[D\x1b[0m";
check(
    'the sequence captured from a real session is neutralised',
    Terminal::plain($fromRealSession) === 'ab',
    bin2hex(Terminal::plain($fromRealSession)),
);

// ---- markdown rendering ----------------------------------------------------
$md = new MarkdownRenderer();

check(
    'model output cannot move the cursor',
    !str_contains($md->render("voici\e[2Jla réponse"), "\e[2J"),
);
check('rendered text keeps its content', str_contains($md->render('bonjour'), 'bonjour'));
check(
    'the renderer still emits its own colours',
    str_contains($md->render('# Titre'), "\e["),
);
check(
    'an injected reset does not survive a code fence',
    !str_contains($md->render("```\n\e[0m\e[2J\n```"), "\e[2J"),
);

// ---- ChatPane --------------------------------------------------------------
function pane(): ChatPane
{
    return new ChatPane(new Terminal(), new MarkdownRenderer());
}

$out = captured(fn() => pane()->addUserMessage("ab\e[A\e[D"));
check('the echoed user line carries no escape movement', !str_contains($out, "\e[A") && !str_contains($out, "\e[D"), bin2hex($out));
check('the echoed user line keeps its text', str_contains($out, 'ab'));

$out = captured(function () {
    $p = pane();
    $p->showToolResult('file_read', "\e[2J\e[Hcontenu du fichier\nligne 2");
});
check('tool output cannot clear the screen', !str_contains($out, "\e[2J"), bin2hex($out));
check('tool output keeps its first line', str_contains($out, 'contenu du fichier'));
check('a multi-line tool result is marked as truncated', str_contains($out, '…'));

$out = captured(function () {
    $p = pane();
    $p->showToolCall('file_write', ['path' => "x\e[2J.php"]);
});
check('tool arguments cannot clear the screen', !str_contains($out, "\e[2J"), bin2hex($out));

// The label is owed only if something is actually streamed.
$out = captured(function () {
    $p = pane();
    $p->beginAssistantMessage();
    $p->flushBuffer();
});
check('a tool-only turn prints no orphan label', !str_contains($out, 'Sherpa'), bin2hex($out));

$out = captured(function () {
    $p = pane();
    $p->beginAssistantMessage();
    $p->appendToken("bonjour\n");
    $p->flushBuffer();
});
check('a turn that streams text does print the label', str_contains($out, 'Sherpa'));
check('and the streamed text with it', str_contains($out, 'bonjour'));

$out = captured(function () {
    $p = pane();
    $p->beginAssistantMessage();
    $p->appendToken('sans saut de ligne final');
    $p->flushBuffer();
});
check('a reply with no trailing newline is still labelled', str_contains($out, 'Sherpa'));
check('and its last line is flushed', str_contains($out, 'sans saut de ligne final'));

// ---- withholding a reply that opens like a tool call -----------------------
$json = '{"name": "file_read", "arguments": {"path": "src/Widget.php"}}';

$out = captured(function () use ($json) {
    $p = pane();
    $p->beginAssistantMessage();
    foreach (str_split($json, 7) as $chunk) {
        $p->appendToken($chunk);
    }
    $p->flushBuffer(wasToolCall: true);
});
check('a recovered tool call is never shown as raw JSON', !str_contains($out, 'file_read'), bin2hex($out));
check('and it leaves no orphan label behind', !str_contains($out, 'Sherpa'), bin2hex($out));

$out = captured(function () use ($json) {
    $p = pane();
    $p->beginAssistantMessage();
    $p->appendToken($json);
    $p->flushBuffer(wasToolCall: false);
});
check('JSON that was not a tool call is still shown', str_contains($out, 'file_read'));

// A reply opening with the tag split across tokens must be held just the same.
$out = captured(function () {
    $p = pane();
    $p->beginAssistantMessage();
    foreach (['<', 'tool', '_call', '>', '{"name":"x"}'] as $t) {
        $p->appendToken($t);
    }
    $p->flushBuffer(wasToolCall: true);
});
check('a tool_call tag split across tokens is withheld', trim($out) === '', bin2hex($out));

// Prose must not pay for any of this: it appears before the flush.
$duringStream = captured(function () {
    $p = pane();
    $p->beginAssistantMessage();
    $p->appendToken("La classe est ZorbulonCalculator.\n");
});
check('ordinary prose is still streamed, not buffered', str_contains($duringStream, 'ZorbulonCalculator'), bin2hex($duringStream));

// ---- box interiors ---------------------------------------------------------
$out = captured(fn() => (new Terminal())->box(1, 1, 12, 4));
check(
    'the box blanks its interior',
    str_contains($out, Terminal::V . Terminal::RESET . str_repeat(' ', 10)),
    bin2hex(substr($out, 0, 120)),
);

// ---- confirmation overlay --------------------------------------------------
$overlay = new ConfirmOverlay(new Terminal(), new FileWriteTool(), new FilePatchTool(), new ShellExecTool());
$summarize = (new ReflectionClass($overlay))->getMethod('summarizeArgs');

$summary = $summarize->invoke($overlay, ['command' => "rm -rf /\e[2J\e[H"], 200);
check('the approval screen cannot be repainted from arguments', !str_contains($summary, "\e"), bin2hex($summary));
check('the approval screen still shows the command', str_contains($summary, 'rm -rf /'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
