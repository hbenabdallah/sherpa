<?php
// What a project is written in. The prompt says "un agent de développement
// {langue}", so getting this wrong is not a display problem.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\SystemPromptBuilder;
use App\Memory\MemoryStore;
use App\Project\DockerConfig;
use App\Project\Project;
use App\Project\StackDetector;
use App\Skills\SkillRegistry;

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

$root = sys_get_temp_dir() . '/sherpa-stack-' . bin2hex(random_bytes(4));

/** @param array<string, string> $files */
function project(string $root, string $name, array $files): string
{
    $path = $root . '/' . $name;
    foreach ($files as $file => $contents) {
        $full = $path . '/' . $file;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $contents);
    }

    return $path;
}

$detector = new StackDetector();

// ---- ecosystems that used to come back "Unknown" ---------------------------
$cases = [
    'go' => [
        ['go.mod' => "module exemple\n\ngo 1.23\n\nrequire (\n\tgithub.com/gin-gonic/gin v1.10.0\n\tgorm.io/gorm v1.25.0\n)\n"],
        'Go', ['Gin', 'GORM'],
    ],
    'rust' => [
        ['Cargo.toml' => "[package]\nname = \"exemple\"\nedition = \"2021\"\n\n[dependencies]\naxum = \"0.7\"\ntokio = \"1\"\n"],
        'Rust', ['Axum', 'Tokio'],
    ],
    'python' => [
        ['pyproject.toml' => "[project]\nrequires-python = \">=3.12\"\ndependencies = [\"fastapi\", \"sqlalchemy\", \"pytest\"]\n"],
        'Python', ['FastAPI', 'SQLAlchemy', 'pytest'],
    ],
    'java' => [
        ['pom.xml' => "<project><dependencies><dependency><artifactId>spring-boot-starter</artifactId></dependency><dependency><artifactId>junit-jupiter</artifactId></dependency></dependencies></project>"],
        'Java', ['Spring Boot', 'JUnit', 'Maven'],
    ],
    'dotnet' => [
        ['Api.csproj' => "<Project><PropertyGroup><TargetFramework>net9.0</TargetFramework></PropertyGroup><ItemGroup><PackageReference Include=\"Microsoft.AspNetCore.App\" /></ItemGroup></Project>"],
        'C#', ['ASP.NET Core'],
    ],
    'ruby' => [
        ['Gemfile' => "source 'https://rubygems.org'\nruby '3.3.0'\ngem 'rails', '~> 7.1'\ngem 'rspec'\n"],
        'Ruby', ['Rails', 'RSpec'],
    ],
    'elixir' => [
        ['mix.exs' => "defmodule Exemple.MixProject do\n  defp deps do\n    [{:phoenix, \"~> 1.7\"}, {:ecto, \"~> 3.11\"}]\n  end\nend\n"],
        'Elixir', ['Phoenix', 'Ecto'],
    ],
    'typescript' => [
        ['package.json' => '{"dependencies":{"next":"15.0.0","react":"19.0.0"},"devDependencies":{"typescript":"5.6.0","vitest":"2.0.0"}}'],
        'TypeScript', ['Next.js', 'React', 'Vitest'],
    ],
];

foreach ($cases as $name => [$files, $language, $expected]) {
    $stack = $detector->detect(project($root, $name, $files));

    check("{$name}: the language is identified", $stack->language === $language, (string) $stack->language);
    check("{$name}: its frameworks are listed", array_values(array_intersect($expected, $stack->parts)) === $expected,
        implode(',', $stack->parts));
}

// A package.json without typescript is JavaScript, not TypeScript.
$plain = $detector->detect(project($root, 'js', ['package.json' => '{"dependencies":{"express":"4"}}']));
check('a project with no typescript is JavaScript', $plain->language === 'JavaScript', (string) $plain->language);
check('and tsconfig.json alone is enough to make it TypeScript',
    $detector->detect(project($root, 'ts2', ['package.json' => '{}', 'tsconfig.json' => '{}']))->language === 'TypeScript');

// ---- the language is not decided by whichever file is read first -----------
// A Symfony application with a package.json for its assets is a PHP project.
$mixed = $detector->detect(project($root, 'mixte', [
    'composer.json' => '{"require":{"php":"~8.4.0","symfony/framework-bundle":"7.4.*"}}',
    'package.json'  => '{"dependencies":{"react":"19.0.0"}}',
]));
check('a back-end manifest wins over a front-end one', $mixed->language === 'PHP', (string) $mixed->language);
check('and the Symfony version is kept', in_array('Symfony 7.4.*', $mixed->parts, true), implode(',', $mixed->parts));

// ---- version kept apart from the language ----------------------------------
// "un agent de développement PHP ~8.4.0" is not a sentence.
check('the language carries no version', $mixed->language === 'PHP' && $mixed->version === '~8.4.0', "{$mixed->language} / {$mixed->version}");
check('but the summary shows both', str_contains($mixed->summary(), 'PHP ~8.4.0'), $mixed->summary());

// ---- infrastructure is added, not substituted for a language ---------------
$dockerised = $detector->detect(project($root, 'docker-go', [
    'go.mod' => "module x\n\ngo 1.23\n",
    'Dockerfile' => "FROM golang\n",
    'k8s/deploy.yaml' => "kind: Deployment\n",
]));
check('a Go service with a Dockerfile is still a Go project', $dockerised->language === 'Go', (string) $dockerised->language);
check('with its infrastructure listed alongside',
    in_array('Docker', $dockerised->parts, true) && in_array('Kubernetes', $dockerised->parts, true), implode(',', $dockerised->parts));

$onlyDocker = $detector->detect(project($root, 'infra', ['Dockerfile' => "FROM alpine\n"]));
check('infrastructure alone names no language', $onlyDocker->language === null && $onlyDocker->parts === ['Docker'], $onlyDocker->summary());

$empty = $detector->detect(project($root, 'vide', ['README.md' => 'rien']));
check('a project with no manifest is honestly unknown', !$empty->isKnown() && $empty->summary() === 'Unknown', $empty->summary());

// A manifest that exists but does not parse still identifies the ecosystem;
// falling through would label the project as something it is not.
$broken = $detector->detect(project($root, 'casse', [
    'composer.json' => '{ ceci ne parse pas',
    'go.mod'        => "module x\n\ngo 1.23\n",
]));
check('an unparseable manifest still names its ecosystem', $broken->language === 'PHP', (string) $broken->language);

// ---- the system prompt follows -------------------------------------------
$memory = new MemoryStore();
$memory->open($root . '/memory.db');
$builder = new SystemPromptBuilder($memory, new SkillRegistry(), $detector);

function projectAt(string $path): Project
{
    return new Project(
        slug: 'x', name: 'exemple', path: $path, memoryDb: '/dev/null',
        docker: new DockerConfig(enabled: false), stack: 'périmé, écrit à la création',
        createdAt: new \DateTimeImmutable(), lastUsedAt: new \DateTimeImmutable(),
    );
}

$goPrompt = $builder->build(projectAt($root . '/go'));
check('the prompt introduces the agent in the project language',
    str_contains($goPrompt, 'agent de développement Go'), substr($goPrompt, 0, 200));
check('and no longer claims to be a PHP/Symfony agent', !str_contains($goPrompt, 'PHP/Symfony'), substr($goPrompt, 0, 200));

$phpPrompt = $builder->build(projectAt($root . '/mixte'));
check('a PHP project still gets a PHP agent', str_contains($phpPrompt, 'agent de développement PHP'), substr($phpPrompt, 0, 200));

$vaguePrompt = $builder->build(projectAt($root . '/vide'));
check('an unrecognised project gets no invented language',
    str_contains($vaguePrompt, "Sherpa, un agent de développement qui s'exécute"), substr($vaguePrompt, 0, 200));

// The stored stack is written at creation and goes stale; the prompt must not
// be reading it.
check('the prompt detects rather than trusting what was stored',
    !str_contains($goPrompt, 'périmé'), substr($goPrompt, 0, 400));
check('and states the stack it actually found', str_contains($goPrompt, 'Stack détectée : Go 1.23'), $goPrompt);

exec('rm -rf ' . escapeshellarg($root));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
