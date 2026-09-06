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

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
