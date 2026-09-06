<?php
// The prompt's two helpers: the menu under "/", and the scrollback Page Up
// opens. Both are text in, text out, so neither needs a terminal here.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Command\SlashCommands;
use App\TUI\Input\LineBuffer;
use App\TUI\Input\SlashMenu;
use App\TUI\Scrollback;
use App\TUI\Terminal;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr(Terminal::plain($detail), 0, 400), "\n";
    }
}

$entries = [
    ...SlashCommands::catalogue(),
    ['name' => '/review', 'hint' => '[request]', 'description' => 'skill · Review a change'],
];
$menu = new SlashMenu(fn() => $entries);
$names = fn() => array_map(fn($e) => $e['name'], array_filter([$menu->current()]));

// ---- when it opens -------------------------------------------------------------
$menu->update('', true);
check('an empty line shows nothing', !$menu->isOpen());
$menu->update('/', true);
check('"/" lists every command and skill', $menu->isOpen() && count($menu->rows(100)) > 8);
$menu->update('/mo', true);
check('"/mo" narrows to /model', ($menu->current()['name'] ?? null) === '/model');
$menu->update('/rev', true);
check('skills are listed beside the commands', ($menu->current()['name'] ?? null) === '/review');
$menu->update('/model gpt', true);
check('past the first space it is arguments: closed', !$menu->isOpen());
$menu->update('/mo', false);
check('with the cursor moved back into the word: closed', !$menu->isOpen());
$menu->update('bonjour', true);
check('an ordinary message: closed', !$menu->isOpen());
$menu->update('/zzz', true);
check('nothing matching: closed', !$menu->isOpen());
$menu->update('/ompac', true);
check('a word from inside the name still finds it', ($menu->current()['name'] ?? null) === '/compact');

// ---- moving in it ----------------------------------------------------------------
$menu->update('/re', true);
$first = $menu->current()['name'] ?? null;
$menu->down();
$second = $menu->current()['name'] ?? null;
check('↓ moves to the next entry', $first !== $second && $second !== null, "{$first} {$second}");
$menu->up();
check('↑ comes back', ($menu->current()['name'] ?? null) === $first);
$menu->up();
check('and wraps round from the top', ($menu->current()['name'] ?? null) !== $first);
$menu->update('/re', true);
check('the choice survives a redraw of the same line', ($menu->current()['name'] ?? null) !== $first);
$menu->update('/res', true);
check('a new letter starts from the top again', ($menu->current()['name'] ?? null) === '/reset');

$menu->dismiss();
check('Escape closes it', !$menu->isOpen());
$menu->update('/res', true);
check('and it stays closed on the same line', !$menu->isOpen());
$menu->update('/re', true);
check('until the line changes', $menu->isOpen());

// ---- what taking an entry does --------------------------------------------------
$byName = array_column($entries, null, 'name');
check('a command with nothing to add is completed without a space', SlashMenu::completion($byName['/reset']) === '/reset');
check('one expecting arguments gets the space', SlashMenu::completion($byName['/remember']) === '/remember ');
check('Enter runs one that needs nothing', SlashMenu::runsAlone($byName['/reset']));
check('and one whose arguments are optional', SlashMenu::runsAlone($byName['/model']));
check('but waits for the required ones', !SlashMenu::runsAlone($byName['/remember']));

$menu->update('/re', true);
$rows = $menu->rows(60);
check('the selected row is highlighted', str_contains($rows[0], "\e[7m"), json_encode($rows));
check('rows are cut to the width', max(array_map(fn($r) => mb_strwidth(Terminal::plain($r)), $rows)) <= 59);
check('and the keys are named under them', str_contains(Terminal::plain(end($rows)), 'Tab complete'));

// ---- the list matches the commands -------------------------------------------------
$help = (string) file_get_contents(dirname(__DIR__) . '/src/Command/SlashCommands.php');
$unhandled = array_filter(
    array_column(SlashCommands::catalogue(), 'name'),
    fn($name) => $name !== '/exit' && !str_contains($help, "case '{$name}':"),
);
check('every command in the menu is one that is handled', $unhandled === [], json_encode(array_values($unhandled)));
preg_match_all("/case '(\/[a-z]+)':/", $help, $handled);
$listed = array_column(SlashCommands::catalogue(), 'name');
check('and every command handled is in the menu', array_diff($handled[1], $listed) === [], json_encode(array_values(array_diff($handled[1], $listed))));

// ---- LineBuffer::set --------------------------------------------------------------
$buffer = new LineBuffer(['/old']);
$buffer->feed('/');
$buffer->set('/model ');
check('a completion replaces the line, cursor at its end', $buffer->text() === '/model ' && $buffer->cursor() === 7);
$buffer->feed('x');
check('and typing carries on after it', $buffer->text() === '/model x');

// ---- the scrollback ------------------------------------------------------------------
$log = new Scrollback();
$log->feed("\e[32m✓ Model\e[0m ready\n");
$log->feed("⠋ Sherpa is thinking…  \r" . str_repeat(' ', 20) . "\r");
$log->feed("\nSherpa Bonjour\e[K\n\e[2J\e[5;3Hmoved\n");
$lines = $log->lines();
check('colours are kept', $lines[0] === "\e[32m✓ Model\e[0m ready", json_encode($lines));
check('the waiting line leaves nothing behind but a blank', trim($lines[1]) === '', json_encode($lines));
check('text after it is kept', $lines[2] === 'Sherpa Bonjour', json_encode($lines));
check('cursor movement and erasing are dropped', $lines[3] === 'moved', json_encode($lines));
$log->pause();
$log->feed('');
$log->resume();
$log->feed('half a line');
check('the line being written is included', end($lines) !== 'half a line' && (($l = $log->lines()) && end($l) === 'half a line'));

$wrapped = Scrollback::wrap("\e[31m" . str_repeat('é', 25) . "\e[0m", 10);
check('a long line is cut to the width', count($wrapped) === 3, json_encode($wrapped));
check('its colour carries on to the next row', str_starts_with($wrapped[1], "\e[31m"), json_encode($wrapped));
check('each row fits', max(array_map(fn($r) => mb_strwidth(Terminal::plain($r)), $wrapped)) === 10);
check('an empty line is one empty row', Scrollback::wrap('', 10) === ['']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
