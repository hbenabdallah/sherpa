<?php
// Verification of TextToolCallParser: recovering tool calls a model emitted as
// prose, without firing on models that merely talk about JSON.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\Tool\TextToolCallParser;

$pass = 0;
$fail = 0;
function check(string $label, $expected, $actual): void
{
    global $pass, $fail;
    $ok = $expected === $actual;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok) {
        echo "        expected: ", json_encode($expected), "\n";
        echo "        actual:   ", json_encode($actual), "\n";
    }
}

$p = new TextToolCallParser();
$known = ['file_read', 'project_grep', 'shell_exec'];
$names = fn(array $calls) => array_map(fn($c) => $c['function']['name'], $calls);
$args = fn(array $calls) => array_map(fn($c) => $c['function']['arguments'], $calls);

// ---- THE ACTUAL FAILURE: bare JSON, no wrapper tags -----------------------
$real = '{
  "name": "file_read",
  "arguments": {
    "path": "src/Widget.php"
  }
}';
check('bare JSON tool call is recovered', ['file_read'], $names($p->parse($real, $known)));
check('its arguments survive', [['path' => 'src/Widget.php']], $args($p->parse($real, $known)));

// ---- The properly tagged form still works ---------------------------------
$tagged = '<tool_call>{"name":"file_read","arguments":{"path":"a.php"}}</tool_call>';
check('<tool_call> wrapped is recovered', ['file_read'], $names($p->parse($tagged, $known)));

// ---- Fenced blocks --------------------------------------------------------
$fenced = "Je vais lire le fichier.\n```json\n{\"name\":\"project_grep\",\"arguments\":{\"pattern\":\"Foo\"}}\n```";
check('fenced json is recovered', ['project_grep'], $names($p->parse($fenced, $known)));

// ---- Alternative key naming ----------------------------------------------
$alt = '{"tool":"file_read","args":{"path":"b.php"}}';
check('tool/args key variant is recovered', ['file_read'], $names($p->parse($alt, $known)));

// ---- Arguments arriving as a JSON string ----------------------------------
$strArgs = '{"name":"file_read","arguments":"{\"path\":\"c.php\"}"}';
check('string-encoded arguments are decoded', [['path' => 'c.php']], $args($p->parse($strArgs, $known)));

// ---- FALSE-POSITIVE GUARDS ------------------------------------------------
check(
    'unknown tool name is rejected',
    [],
    $p->parse('{"name":"rm_minus_rf","arguments":{"path":"/"}}', $known),
);
check(
    'prose about JSON does not trigger a call',
    [],
    $p->parse('Le format attendu est {"name": "...", "arguments": {...}} pour chaque appel.', $known),
);
check(
    'a JSON object that is not a tool call is ignored',
    [],
    $p->parse('{"path":"src/Widget.php","class":"Widget"}', $known),
);
check(
    'arguments must be an object, not a scalar',
    [],
    $p->parse('{"name":"file_read","arguments":42}', $known),
);
check('empty content yields nothing', [], $p->parse('', $known));
check('no known tools yields nothing', [], $p->parse($real, []));

// ---- Structure handling ---------------------------------------------------
$two = '{"name":"file_read","arguments":{"path":"a"}} then {"name":"project_grep","arguments":{"pattern":"b"}}';
check('two calls in one reply are both recovered', ['file_read', 'project_grep'], $names($p->parse($two, $known)));

$dup = '<tool_call>{"name":"file_read","arguments":{"path":"a.php"}}</tool_call>';
check('same call seen twice is not duplicated', 1, count($p->parse($dup, $known)));

$nested = '{"name":"shell_exec","arguments":{"command":"echo hi","env":{"A":{"B":"c"}}}}';
check('nested argument objects parse', ['shell_exec'], $names($p->parse($nested, $known)));

$braceInString = '{"name":"shell_exec","arguments":{"command":"awk \'{print $1}\' file.txt"}}';
check(
    'braces inside strings do not break scanning',
    [['command' => "awk '{print \$1}' file.txt"]],
    $args($p->parse($braceInString, $known)),
);

$withProse = "Je vais lire ce fichier.\n{\"name\":\"file_read\",\"arguments\":{\"path\":\"d.php\"}}\nVoilà.";
check('call embedded in surrounding prose is found', ['file_read'], $names($p->parse($withProse, $known)));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
