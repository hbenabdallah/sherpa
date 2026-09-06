<?php
// The bridge between what a model writes and what a PHP handler will accept.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Agent\Tool\ToolCall;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", substr($detail, 0, 300), "\n";
    }
}

#[AsTool(name: 'typed', description: 'Renvoie ce qu\'il a reçu', permission: Permission::AUTO)]
final class TypedTool
{
    public function __invoke(
        #[Param('un entier')] int $count,
        #[Param('un booléen')] bool $flag = false,
        #[Param('un flottant')] float $ratio = 0.0,
        #[Param('du texte')] string $label = '',
    ): string {
        return json_encode([
            'count' => $count, 'flag' => $flag, 'ratio' => $ratio, 'label' => $label,
            'types' => [get_debug_type($count), get_debug_type($flag), get_debug_type($ratio), get_debug_type($label)],
        ]);
    }
}

$box = new Toolbox([new TypedTool()]);

function call(Toolbox $box, array $args): array
{
    $result = $box->execute(new ToolCall(id: 'c1', name: 'typed', arguments: $args));

    return [$result, json_decode($result->content, true)];
}

// The schema says integer; the model writes a string anyway. Arguments are
// spread as named parameters into a strict_types call, so this used to be a
// TypeError and the tool simply never ran.
[$result, $out] = call($box, ['count' => '10']);
check('a numeric string reaches an int parameter', !$result->isError && $out['count'] === 10, $result->content);

[$result, $out] = call($box, ['count' => 1, 'ratio' => '0.75']);
check('and reaches a float parameter', !$result->isError && $out['ratio'] === 0.75, $result->content);

[$result, $out] = call($box, ['count' => 1, 'flag' => 'true']);
check('the string "true" means true', !$result->isError && $out['flag'] === true, $result->content);

// PHP casts the string "false" to true, so a naive cast would turn a refusal
// into consent — on a parameter whose whole job is to authorise something.
[$result, $out] = call($box, ['count' => 1, 'flag' => 'false']);
check('the string "false" means false, not true', !$result->isError && $out['flag'] === false, $result->content);

[$result, $out] = call($box, ['count' => 1, 'flag' => 0]);
check('the number 0 means false', !$result->isError && $out['flag'] === false, $result->content);

[$result, $out] = call($box, ['count' => 1, 'label' => 42]);
check('a number reaching a string parameter is stringified', !$result->isError && $out['label'] === '42', $result->content);

// Real booleans and ints must survive untouched.
[$result, $out] = call($box, ['count' => 7, 'flag' => true, 'ratio' => 1.5, 'label' => 'x']);
check('well-typed arguments are left alone', !$result->isError && $out['types'] === ['int', 'bool', 'float', 'string'], $result->content);

// Nonsense stays an error the model can read, rather than becoming a silent 0.
[$result, $out] = call($box, ['count' => 'beaucoup']);
check('a non-numeric string is still refused', $result->isError, $result->content);

$result = $box->execute(new ToolCall(id: 'c2', name: 'inconnu', arguments: []));
check('an unknown tool is reported, not thrown', $result->isError && str_contains($result->content, 'not found'), $result->content);

// ---- the schema handed to Ollama -------------------------------------------
#[AsTool(name: 'sans_args', description: 'Ne prend rien', permission: Permission::AUTO)]
final class NoArgsTool
{
    public function __invoke(): string { return 'ok'; }
}

$schema = (new Toolbox([new NoArgsTool()]))->getDefinitions()[0]->toFunctionSchema();
$json = json_encode($schema);

// An empty PHP array encodes as [], and JSON Schema says "properties" is an
// object. Ollama answers 400 "Value looks like object, but can't find closing
// '}' symbol" and the whole turn dies — for every tool in the request, not just
// this one. No native tool takes zero arguments, so nothing caught it until an
// MCP server exposed one that does.
check('a tool with no arguments still declares properties as an object',
    str_contains($json, '"properties":{}'), $json);
check('and required stays a list', str_contains($json, '"required":[]'), $json);

$withArgs = (new Toolbox([new TypedTool()]))->getDefinitions()[0]->toFunctionSchema();
check('a tool with arguments is unaffected',
    ($withArgs['function']['parameters']['properties']['count']['type'] ?? null) === 'integer',
    json_encode($withArgs));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
