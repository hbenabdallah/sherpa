<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use stdClass;

final readonly class ToolDefinition
{
    /**
     * @param array<string, array{type: string, description: string, required?: bool, schema?: array<string, mixed>}> $parameters
     *        by name; 'schema' is the rest of an MCP server's JSON Schema for it
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
        public Permission $permission,
        public object $handler,
        /** Brings text from outside the project and the user; see Provenance. */
        public bool $untrusted = false,
    ) {}

    /**
     * @return array{type: 'function', function: array{name: string, description: string, parameters: array{type: 'object', properties: array<string, array<string, mixed>>|stdClass, required: list<string>}}}
     */
    public function toFunctionSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters as $name => $param) {
            // 'schema': the rest of an MCP server's JSON Schema for it.
            $properties[$name] = [
                'type'        => $param['type'],
                'description' => $param['description'],
            ] + ($param['schema'] ?? []);
            if ($param['required'] ?? false) {
                $required[] = $name;
            }
        }

        return [
            'type'     => 'function',
            'function' => [
                'name'        => $this->name,
                'description' => $this->description,
                'parameters'  => [
                    'type' => 'object',
                    // An empty PHP array encodes as [], and a JSON Schema
                    // "properties" has to be an object. Ollama rejects the
                    // whole request with a 400 over it — which no native tool
                    // ever triggered, because every one of them takes at least
                    // one argument. An MCP server's need not.
                    'properties' => $properties === [] ? new stdClass() : $properties,
                    'required'   => $required,
                ],
            ],
        ];
    }
}
