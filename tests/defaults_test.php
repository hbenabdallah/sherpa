<?php
// Defaults live in two places, and they must say the same thing.
//
// docker-compose.yml passes every variable as ${VAR:-default}: a variable it
// passed empty would be defined, and a defined variable never falls back to
// Symfony's own default. config/services.yaml holds env(VAR) defaults for the
// runs that have no compose in between — Sherpa on the host, which is how it
// is meant to be used. Two copies of one fact drift, silently, and the symptom
// is a Sherpa that behaves differently depending on how it was started.

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 600), "\n";
    }
}

$root = dirname(__DIR__);

// ---- what compose passes, and with which fallback --------------------------
$compose = Yaml::parseFile($root . '/docker-compose.yml');
$composeDefaults = [];
foreach ($compose['services']['app']['environment'] ?? [] as $entry) {
    if (preg_match('/^([A-Z_][A-Z0-9_]*)=\$\{\1:-(.*)\}$/', (string) $entry, $m) === 1) {
        $composeDefaults[$m[1]] = $m[2];
    }
}
check('compose declares its defaults in the ${VAR:-default} form', count($composeDefaults) > 10, json_encode($composeDefaults));

// ---- what Symfony falls back to without compose ----------------------------
$services = Yaml::parseFile($root . '/config/services.yaml');
$symfonyDefaults = [];
foreach ($services['parameters'] ?? [] as $key => $value) {
    if (preg_match('/^env\(([A-Z_][A-Z0-9_]*)\)$/', (string) $key, $m) === 1) {
        $symfonyDefaults[$m[1]] = (string) $value;
    }
}

// ---- every variable Sherpa reads -------------------------------------------
$read = [];
// Two globs rather than GLOB_BRACE, which musl — the static PHP of a host
// install — does not have.
foreach ([...glob($root . '/config/*.yaml') ?: [], ...glob($root . '/config/packages/*.yaml') ?: []] as $file) {
    preg_match_all('/%env\((?:[a-z]+:)*([A-Z_][A-Z0-9_]*)\)%/', (string) file_get_contents($file), $m);
    foreach ($m[1] as $name) {
        $read[$name] = true;
    }
}
check('the variables Sherpa reads were found', isset($read['OLLAMA_URL'], $read['SHERPA_API_KEY']), implode(', ', array_keys($read)));

// APP_SECRET and APP_ENV come from .env (or .env.dist) through Symfony's
// runtime on the host, and are not Sherpa's own settings.
$sherpaOwn = array_filter(array_keys($read), fn(string $name) => !str_starts_with($name, 'APP_'));

$undefaulted = array_values(array_filter($sherpaOwn, fn(string $name) => !array_key_exists($name, $symfonyDefaults)));
check('every setting Sherpa reads has a default without compose',
    $undefaulted === [], 'with no default outside compose: ' . implode(', ', $undefaulted));

$drift = [];
foreach ($symfonyDefaults as $name => $value) {
    if (array_key_exists($name, $composeDefaults) && $composeDefaults[$name] !== $value) {
        $drift[] = "{$name} : compose « {$composeDefaults[$name]} », services.yaml « {$value} »";
    }
}
check('compose and services.yaml agree on every default they share', $drift === [], implode(' | ', $drift));

$missingInCompose = array_values(array_filter(
    $sherpaOwn,
    fn(string $name) => !array_key_exists($name, $composeDefaults),
));
check('and compose passes every setting Sherpa reads', $missingInCompose === [], implode(', ', $missingInCompose));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
