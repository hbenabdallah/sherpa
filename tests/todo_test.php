<?php
// The plan the model keeps for a long task: written whole, read back, shown.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\TodoList;
use App\Tool\TodoWriteTool;
use App\TUI\ChatPane;
use App\TUI\MarkdownRenderer;
use App\TUI\Terminal;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 300), "\n";
    }
}

$todos = new TodoList();
$tool = new TodoWriteTool($todos);

$out = $tool("Plan:\n[x] Read Invoice.php\n[>] Fix subtotal()\n[ ] Run the tests\n");
check('the three states are read', array_column($todos->tasks(), 'status') === [TodoList::DONE, TodoList::DOING, TodoList::TODO],
    json_encode($todos->tasks()));
check('a line that is not a task is left out', count($todos->tasks()) === 3 && $todos->tasks()[0]['text'] === 'Read Invoice.php');
check('the model reads back where the plan stands', str_starts_with($out, 'Plan (1/3 done):') && str_contains($out, '[>] Fix subtotal()'), $out);

$todos->replace("- [X] one\n1. [-] two\n* [ ] three\n2) [~] four");
check('the usual ways of writing a checkbox all count',
    array_column($todos->tasks(), 'status') === [TodoList::DONE, TodoList::DOING, TodoList::TODO, TodoList::DOING],
    json_encode($todos->tasks()));

$tool("[x] a\n[ ] b");
check('each write replaces the whole list', count($todos->tasks()) === 2 && $todos->progress() === '1/2 done');

$cleared = $tool('nothing that looks like a task');
check('a list with no task clears the plan and says how to write one',
    $todos->isEmpty() && str_contains($cleared, '"[ ] task"'), $cleared);

// ---- on screen ---------------------------------------------------------------
$pane = new ChatPane(new Terminal(), new MarkdownRenderer());
ob_start();
$pane->showToolCall('todo_write', ['tasks' => "[x] a\n[ ] b"]);
$call = ob_get_clean();
check('the call itself is not printed: the plan follows it', $call === '', json_encode($call));

ob_start();
$pane->showToolResult('todo_write', "Plan (1/3 done):\n[x] Read Invoice.php\n[>] Fix subtotal()\n[ ] Run the tests");
$shown = Terminal::plain(ob_get_clean());
check('the plan is shown whole, with where it stands', str_contains($shown, 'Plan · 1/3 done'), $shown);
check('each task with its state',
    str_contains($shown, '✓ Read Invoice.php') && str_contains($shown, '▶ Fix subtotal()') && str_contains($shown, '○ Run the tests'), $shown);

ob_start();
$pane->showToolResult('todo_write', 'Plan cleared', isError: true);
check('an error is shown like any tool error', str_contains(ob_get_clean(), '✗'));

// ---- found by the container, like every tool --------------------------------
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$service = $kernel->getContainer()->get(TodoWriteTool::class);
$def = (new App\Agent\Tool\Toolbox([$service]))->find('todo_write');
check('todo_write is registered as a tool', $service instanceof TodoWriteTool && $def !== null);
check('and runs without asking: it only writes the plan', $def?->permission === App\Agent\Tool\Permission::AUTO);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
