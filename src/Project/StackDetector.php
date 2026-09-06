<?php

namespace App\Project;

/**
 * Works out what a project is written in, from the files that declare it.
 *
 * This used to read composer.json and package.json and nothing else, so every
 * project that was not PHP or JavaScript came out as "Unknown" — and the system
 * prompt went on calling itself a PHP/Symfony agent regardless. The point of a
 * manifest table rather than a longer ladder of ifs is that adding an ecosystem
 * is a row, and each row says how to recognise its own frameworks.
 */
class StackDetector
{
    public function detect(string $projectPath): Stack
    {
        foreach ($this->probes() as $probe) {
            $stack = $probe($projectPath);

            if ($stack !== null) {
                return new Stack($stack->language, $stack->version, array_merge($stack->parts, $this->infrastructure($projectPath)));
            }
        }

        $infrastructure = $this->infrastructure($projectPath);

        return $infrastructure === [] ? Stack::unknown() : new Stack(null, null, $infrastructure);
    }

    /**
     * Ordered: the first manifest that matches names the project.
     *
     * A Symfony application with a package.json for its assets is a PHP
     * project, and a Go service with a Dockerfile is a Go project. Front-end
     * manifests come after back-end ones for that reason, and the language a
     * project is written in is not decided by whichever file was read first.
     *
     * @return array<int, callable(string): ?Stack>
     */
    private function probes(): array
    {
        return [
            $this->php(...),
            $this->go(...),
            $this->rust(...),
            $this->python(...),
            $this->java(...),
            $this->dotnet(...),
            $this->ruby(...),
            $this->elixir(...),
            $this->javascript(...),
        ];
    }

    private function php(string $path): ?Stack
    {
        $data = $this->json($path . '/composer.json');
        if ($data === null) {
            return null;
        }

        $require = array_merge($data['require'] ?? [], $data['require-dev'] ?? []);
        $version = $require['php'] ?? null;

        return new Stack(
            'PHP',
            is_string($version) ? $version : null,
            $this->match($require, [
                'symfony/framework-bundle' => 'Symfony',
                'laravel/framework'        => 'Laravel',
                'api-platform/core'        => 'API Platform',
                'doctrine/orm'             => 'Doctrine ORM',
                'doctrine/dbal'            => 'Doctrine DBAL',
                'phpunit/phpunit'          => 'PHPUnit',
                'symfony/test-pack'        => 'PHPUnit',
                'pestphp/pest'             => 'Pest',
                'phpstan/phpstan'          => 'PHPStan',
            ], withVersion: ['symfony/framework-bundle' => 'Symfony', 'laravel/framework' => 'Laravel']),
        );
    }

    private function go(string $path): ?Stack
    {
        $mod = $this->read($path . '/go.mod');
        if ($mod === null) {
            return null;
        }

        preg_match('/^go\s+([0-9.]+)/m', $mod, $version);

        return new Stack(
            'Go',
            $version[1] ?? null,
            $this->matchText($mod, [
                'github.com/gin-gonic/gin'  => 'Gin',
                'github.com/labstack/echo'  => 'Echo',
                'github.com/gofiber/fiber'  => 'Fiber',
                'gorm.io/gorm'              => 'GORM',
                'github.com/stretchr/testify' => 'Testify',
            ]),
        );
    }

    private function rust(string $path): ?Stack
    {
        $cargo = $this->read($path . '/Cargo.toml');
        if ($cargo === null) {
            return null;
        }

        preg_match('/^edition\s*=\s*"([0-9]+)"/m', $cargo, $edition);

        return new Stack(
            'Rust',
            isset($edition[1]) ? 'édition ' . $edition[1] : null,
            $this->matchText($cargo, [
                'axum'   => 'Axum',
                'actix-web' => 'Actix Web',
                'rocket' => 'Rocket',
                'tokio'  => 'Tokio',
                'serde'  => 'Serde',
                'sqlx'   => 'SQLx',
            ]),
        );
    }

    private function python(string $path): ?Stack
    {
        $manifest = $this->read($path . '/pyproject.toml')
            ?? $this->read($path . '/requirements.txt')
            ?? $this->read($path . '/Pipfile')
            ?? $this->read($path . '/setup.py');

        if ($manifest === null) {
            return null;
        }

        preg_match('/requires-python\s*=\s*"([^"]+)"/', $manifest, $version);

        return new Stack(
            'Python',
            $version[1] ?? null,
            $this->matchText($manifest, [
                'django'    => 'Django',
                'fastapi'   => 'FastAPI',
                'flask'     => 'Flask',
                'sqlalchemy' => 'SQLAlchemy',
                'pydantic'  => 'Pydantic',
                'pytest'    => 'pytest',
            ]),
        );
    }

    private function java(string $path): ?Stack
    {
        $manifest = $this->read($path . '/pom.xml')
            ?? $this->read($path . '/build.gradle')
            ?? $this->read($path . '/build.gradle.kts');

        if ($manifest === null) {
            return null;
        }

        $kotlin = str_contains($manifest, 'kotlin');

        $frameworks = $this->matchText($manifest, [
            'spring-boot' => 'Spring Boot',
            'quarkus'     => 'Quarkus',
            'micronaut'   => 'Micronaut',
            'hibernate'   => 'Hibernate',
            'junit'       => 'JUnit',
        ]);

        // array_merge, not +: the union operator keys on index, so the build
        // tool would vanish the moment a framework was found before it.
        return new Stack(
            $kotlin ? 'Kotlin' : 'Java',
            null,
            array_merge($frameworks, [is_file($path . '/pom.xml') ? 'Maven' : 'Gradle']),
        );
    }

    private function dotnet(string $path): ?Stack
    {
        $projects = glob($path . '/*.csproj') ?: glob($path . '/*/*.csproj') ?: [];
        if ($projects === []) {
            return null;
        }

        $manifest = $this->read($projects[0]) ?? '';
        preg_match('/<TargetFramework>([^<]+)</', $manifest, $target);

        return new Stack(
            'C#',
            $target[1] ?? null,
            $this->matchText($manifest, [
                'Microsoft.AspNetCore' => 'ASP.NET Core',
                'EntityFrameworkCore'  => 'Entity Framework Core',
                'xunit'                => 'xUnit',
            ]),
        );
    }

    private function ruby(string $path): ?Stack
    {
        $gemfile = $this->read($path . '/Gemfile');
        if ($gemfile === null) {
            return null;
        }

        preg_match('/^ruby\s+["\']([^"\']+)/m', $gemfile, $version);

        return new Stack(
            'Ruby',
            $version[1] ?? null,
            $this->matchText($gemfile, [
                'rails'   => 'Rails',
                'sinatra' => 'Sinatra',
                'rspec'   => 'RSpec',
            ]),
        );
    }

    private function elixir(string $path): ?Stack
    {
        $mix = $this->read($path . '/mix.exs');
        if ($mix === null) {
            return null;
        }

        return new Stack('Elixir', null, $this->matchText($mix, [
            'phoenix' => 'Phoenix',
            'ecto'    => 'Ecto',
        ]));
    }

    private function javascript(string $path): ?Stack
    {
        $data = $this->json($path . '/package.json');
        if ($data === null) {
            return null;
        }

        $deps = array_merge($data['dependencies'] ?? [], $data['devDependencies'] ?? []);
        $typed = isset($deps['typescript']) || is_file($path . '/tsconfig.json');

        return new Stack(
            $typed ? 'TypeScript' : 'JavaScript',
            null,
            $this->match($deps, [
                'next'     => 'Next.js',
                'nuxt'     => 'Nuxt',
                'react'    => 'React',
                'vue'      => 'Vue',
                '@angular/core' => 'Angular',
                'svelte'   => 'Svelte',
                'express'  => 'Express',
                'nestjs'   => 'NestJS',
                '@nestjs/core' => 'NestJS',
                'vitest'   => 'Vitest',
                'jest'     => 'Jest',
            ]),
        );
    }

    /** @return array<int, string> */
    private function infrastructure(string $path): array
    {
        $found = [];

        foreach (['Dockerfile', 'docker-compose.yml', 'compose.yaml', 'compose.yml'] as $file) {
            if (is_file($path . '/' . $file)) {
                $found[] = 'Docker';
                break;
            }
        }

        if (is_dir($path . '/k8s') || is_dir($path . '/kubernetes') || is_dir($path . '/helm')) {
            $found[] = 'Kubernetes';
        }

        return $found;
    }

    /**
     * @param array<string, mixed>  $dependencies
     * @param array<string, string> $known        package => display name
     * @param array<string, string> $withVersion  packages whose version is worth showing
     *
     * @return array<int, string>
     */
    private function match(array $dependencies, array $known, array $withVersion = []): array
    {
        $found = [];

        foreach ($known as $package => $label) {
            if (!isset($dependencies[$package]) || in_array($label, $found, true)) {
                continue;
            }

            $found[] = isset($withVersion[$package]) && is_string($dependencies[$package])
                ? $label . ' ' . $dependencies[$package]
                : $label;
        }

        return $found;
    }

    /**
     * For manifests that are not key/value: go.mod, Cargo.toml, a Gemfile.
     *
     * @param array<string, string> $known needle => display name
     *
     * @return array<int, string>
     */
    private function matchText(string $manifest, array $known): array
    {
        $found = [];

        foreach ($known as $needle => $label) {
            if (str_contains($manifest, $needle) && !in_array($label, $found, true)) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /** @return array<string, mixed>|null */
    private function json(string $file): ?array
    {
        $raw = $this->read($file);
        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);

        // A manifest that exists but does not parse still identifies the
        // ecosystem; treating it as absent would fall through to the next probe
        // and label the project as something it is not.
        return is_array($data) ? $data : [];
    }

    private function read(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $contents = @file_get_contents($file);

        return $contents === false ? null : $contents;
    }
}
