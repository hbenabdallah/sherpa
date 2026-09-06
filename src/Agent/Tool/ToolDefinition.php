<?php

namespace App\Agent\Tool;

final class ToolDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        public readonly Permission $permission,
        public readonly object $handler,
    ) {}

    public function toFunctionSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters as $name => $param) {
            $properties[$name] = [
                'type'        => $param['type'],
                'description' => $param['description'],
            ];
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
                    'properties' => $properties === [] ? new \stdClass() : $properties,
                    'required'   => $required,
                ],
            ],
        ];
    }
}