<?php
// The input line when there is no libreadline — the static PHP Sherpa runs
// with on the host has none. Before this editor existed, that case fell back on
// a bare fgets(): arrow keys typed "^[[A" into the line, and a Ctrl+C at the
// prompt waited for Enter. Keys in, state out: no terminal needed to test it.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\TUI\Input\KeyReader;
use App\TUI\Input\LineBuffer;
use App\TUI\Input\LineView;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 400), "\n";
    }
}

/** Type $keys into a fresh buffer; return it and the last non-null result. */
function typed(array $keys, array $history = []): array
{
    $buffer = new LineBuffer($history);
    $result = null;
    foreach ($keys as $key) {
        $result = $buffer->feed($key) ?? $result;
    }

    return [$buffer, $result];
}

// ---- bytes into keys ---------------------------------------------------------
[$keys, $rest] = KeyReader::split("ab\e[Dé東\e[3~\eOH\r");
check('letters, multibyte characters and escape sequences come out one key each',
    $keys === ['a', 'b', "\e[D", 'é', '東', "\e[3~", "\eOH", "\r"] && $rest === '', json_encode($keys));

// A read can end anywhere. What cannot be completed yet waits for the next one.
[$keys, $rest] = KeyReader::split("x\xE6\x9D");
check('half a character is held back, not decoded as garbage', $keys === ['x'] && $rest === "\xE6\x9D");
[$keys, $rest] = KeyReader::split($rest . "\xB1!");
check('and completed by the next read', $keys === ['東', '!'] && $rest === '', json_encode($keys));

[$keys, $rest] = KeyReader::split("\e[");
check('so is half an escape sequence', $keys === [] && $rest === "\e[");

// A broken lead byte must not take Enter down with it: the line would never end.
[$keys] = KeyReader::split("é\xE6\x9D\n");
check('a broken character is dropped alone, and the key after it survives',
    $keys === ['é', "\n"], json_encode(array_map('bin2hex', $keys)));

// ---- editing -----------------------------------------------------------------
[$b, $result] = typed(['a', 'b', 'c', "\e[D", "\e[D", 'X', "\r"]);
check('typing in the middle of a line inserts there', $b->text() === 'aXbc' && $result === LineBuffer::SUBMIT, $b->text());

[$b] = typed(['é', '東', "\x7f"]);
check('backspace removes a whole character, however many bytes it has', $b->text() === 'é', $b->text());

[$b] = typed(['a', 'b', 'c', "\e[H", "\e[3~"]);
check('Home, then Delete, removes the first character', $b->text() === 'bc', $b->text());

[$b] = typed(['b', 'o', 'n', 'j', 'o', 'u', 'r', ' ', 'l', 'e', ' ', 'm', 'o', 'n', 'd', 'e', "\x17"]);
check('Ctrl+W removes the word before the cursor', $b->text() === 'bonjour le ', $b->text());

[$b] = typed(['a', 'b', 'c', "\x01", 'X', "\x0b"]);
check('Ctrl+A goes to the start and Ctrl+K cuts to the end', $b->text() === 'X', $b->text());

[$b] = typed(['a', 'b', 'c', "\e[D", "\x15"]);
check('Ctrl+U cuts to the start', $b->text() === 'c' && $b->cursor() === 0, $b->text());

// A key this editor does not bind must not end up in the text: echoed back,
// it would move the cursor on screen.
[$b] = typed(['a', "\e[1;5C", "\eOP", "\x1c", 'b']);
check('unbound keys and control characters are ignored, not inserted', $b->text() === 'ab', bin2hex($b->text()));

// ---- the end of a line, or of input ------------------------------------------
[, $result] = typed(["\x04"]);
check('Ctrl+D on an empty line is the end of input', $result === LineBuffer::EOF);
[$b, $result] = typed(['a', 'b', "\e[D", "\x04"]);
check('and on a non-empty one, a Delete', $result === null && $b->text() === 'a', $b->text());

// ---- history -------------------------------------------------------------------
$history = ['premier', 'second'];
[$b] = typed(["\e[A"], $history);
check('Up recalls the newest line first', $b->text() === 'second');
[$b] = typed(["\e[A", "\e[A", "\e[A"], $history);
check('and stops at the oldest', $b->text() === 'premier');
[$b] = typed(['b', 'r', 'o', "\e[A", "\e[B"], $history);
check('Down past the newest gives back what was being typed', $b->text() === 'bro', $b->text());

// ---- what fits on screen -------------------------------------------------------
[$shown, $column] = LineView::window(mb_str_split('bonjour'), 3, 40);
check('a short line is shown whole, the cursor where it is', $shown === 'bonjour' && $column === 3);

[$shown, $column] = LineView::window(mb_str_split('abcdefghijklmnopqrstuvwxyz'), 26, 10);
check('a long line slides so the cursor at its end stays visible',
    $shown === 'rstuvwxyz' && $column === 9, "{$shown} / {$column}");

[, $column] = LineView::window(mb_str_split('東京x'), 2, 40);
check('wide characters count for two columns', $column === 4, (string) $column);

// ---- messages of several lines -------------------------------------------------
$b = new LineBuffer();
foreach (['o', 'n', 'e', "\e\r", 't', 'w', 'o'] as $key) {
    $b->feed($key);
}
check('Alt+Enter starts a new line instead of sending', $b->text() === "one\ntwo", json_encode($b->text()));
foreach (["\e[13;2u", "\e[13;5u", "\e[27;5;13~"] as $key) {
    $x = new LineBuffer();
    $x->feed('a');
    check('so does ' . json_encode($key) . ', where the terminal tells Shift/Ctrl+Enter apart', $x->feed($key) === null && $x->text() === "a\n");
}

$b = new LineBuffer();
foreach (str_split('first \\') as $key) {
    $b->feed($key);
}
check('"\\" then Enter goes on to the next line', $b->feed("\r") === null && $b->text() === "first \n", json_encode($b->text()));
check('and Enter after that sends it all', $b->feed("\r") === LineBuffer::SUBMIT);

// A paste: its line breaks, whichever the system, are text.
$b = new LineBuffer();
$results = array_map(fn(string $k) => $b->feed($k), [LineBuffer::PASTE_START, 'l', 'i', 'n', 'e', ' ', '1', "\r", "\n", 'l', '2', "\r", 'l', '3', "\t", 'x', LineBuffer::PASTE_END]);
check('a pasted text is taken whole, never sent line by line', array_filter($results) === [], json_encode($results));
check('its line breaks kept, CRLF and CR alike, tabs as spaces', $b->text() === "line 1\nl2\nl3    x", json_encode($b->text()));
check('and Enter sends it, once', $b->feed("\r") === LineBuffer::SUBMIT);

$b = new LineBuffer();
foreach ([LineBuffer::PASTE_START, 'a', "\e[31m", 'b', "\x07", LineBuffer::PASTE_END] as $key) {
    $b->feed($key);
}
check('what could move the cursor or ring the bell is not pasted', $b->text() === 'ab', json_encode($b->text()));

// Moving about a message of several lines.
$b = new LineBuffer(['an older message']);
$b->paste("short\nlonger line\nend");
$b->feed("\e[A");
check('Up moves to the line above, at the same column where it can', $b->cursor() === strlen("short\nlon"), (string) $b->cursor());
$b->feed("\e[A");
check('and again, the column kept', $b->cursor() === 3, (string) $b->cursor());
$b->feed("\e[A");
check('and above the first line, into history as before', $b->text() === 'an older message', json_encode($b->text()));
$b->feed("\e[B");
check('Down past the last line comes back to the draft', $b->text() === "short\nlonger line\nend", json_encode($b->text()));

$b = new LineBuffer();
$b->paste("ab\nlonger");
$b->feed("\e[A");
check('a line above shorter than the column: to its end', $b->cursor() === 2, (string) $b->cursor());

$b = new LineBuffer();
$b->paste("abc\ndef");
$b->feed("\e[A");
$b->feed("\x01");
check('Home is the start of the current line, not of the message', $b->cursor() === 0);
$b->feed("\x05");
check('End its end', $b->cursor() === 3, (string) $b->cursor());
$b->feed("\e[B");
$b->feed("\x15");
check('Ctrl+U empties the current line only', $b->text() === "abc\n", json_encode($b->text()));

// Without the bracketed-paste markers, a paste still shows as one.
check('a lone Enter is a keypress', !KeyReader::isPaste("\r"));
check('text ending in Enter could be typed fast', !KeyReader::isPaste("hello\r") && !KeyReader::isPaste("hello\r\n"));
check('a line break with more text after it can only be a paste', KeyReader::isPaste("line 1\nline 2") && KeyReader::isPaste("a\r\nb\r\n"));
check('and a bracketed paste is left to its markers', !KeyReader::isPaste("\e[200~a\nb\e[201~"));
check('Alt+Enter reads as one key', KeyReader::split("\e\r")[0] === ["\e\r"]);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
