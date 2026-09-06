<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class Toolbox
{
    /** @var array<string, ToolDefinition> */
    private array $definitions = [];

    public function __construct(
        #[AutowireIterator('agent.tool')] iterable $tools,
    ) {
        foreach ($tools as $tool) {
            $def = $this->buildDefinition($tool);
            $this->definitions[$def->name] = $def;
        }
    }

    private function buildDefinition(object $tool): ToolDefinition
    {
        $ref = new \ReflectionClass($tool);
        $attrs = $ref->getAttributes(AsTool::class);

        if (empty($attrs)) {
            throw new \LogicException(sprintf('Tool %s must declare #[AsTool]', $ref->getName()));
        }

        /** @var AsTool $asTool */
        $asTool = $attrs[0]->newInstance();
        $method = $ref->getMethod('__invoke');

        return new ToolDefinition(
            name: $asTool->name,
            description: $asTool->description,
            parameters: $this->extractParameters($method),
            permission: $asTool->permission,
            handler: $tool,
        );
    }

    private function extractParameters(\ReflectionMethod $method): array
    {
        $params = [];

        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            $nullable = $type instanceof \ReflectionNamedType && $type->allowsNull();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'string';

            $jsonType = match (true) {
                in_array($typeName, ['int', 'integer'])     => 'integer',
                in_array($typeName, ['float', 'double'])    => 'number',
                in_array($typeName, ['bool', 'boolean'])    => 'boolean',
                $typeName === 'array'                       => 'array',
                default                                     => 'string',
            };

            $description = '';
            $paramAttrs = $param->getAttributes(Param::class);
            if (!empty($paramAttrs)) {
                $description = $paramAttrs[0]->newInstance()->description;
            }

            $params[$param->getName()] = [
                'type'        => $jsonType,
                'description' => $description,
                'required'    => !$nullable && !$param->isOptional(),
            ];
        }

        return $params;
    }

    /**
     * Add a tool that no #[AsTool] class describes — an MCP server's, say.
     *
     * A name already taken is refused rather than replaced: silently shadowing
     * file_read with a remote tool of the same name would redirect the agent's
     * own filesystem access to somebody else's process.
     */
    public function register(ToolDefinition $definition): void
    {
        if (isset($this->definitions[$definition->name])) {
            throw new \LogicException("Un tool nommé « {$definition->name} » existe déjà.");
        }

        $this->definitions[$definition->name] = $definition;
    }

    /** @return ToolDefinition[] */
    public function getDefinitions(): array
    {
        return array_values($this->definitions);
    }

    public function find(string $name): ?ToolDefinition
    {
        return $this->definitions[$name] ?? null;
    }

    /**
     * Bend the model's arguments to the types the handler declares.
     *
     * A JSON schema is a request, not a guarantee: a small model writes
     * `"offset": "10"` and `"replaces": "false"` as often as not, and a large
     * one still does it sometimes. Coercion is cheap; the failure it prevents
     * is a tool that never runs. Arguments are
     * spread as named parameters into a strict_types call, so a string where an
     * int is declared is a TypeError — the tool never runs, and the model is
     * told about a PHP type rather than about anything it can fix. Note that
     * the string "false" is truthy in PHP: a plain cast would turn a refusal
     * into consent.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function coerce(ToolDefinition $def, array $arguments): array
    {
        foreach ($arguments as $name => $value) {
            $type = $def->parameters[$name]['type'] ?? null;

            $arguments[$name] = match (true) {
                $type === null, !is_scalar($value) => $value,
                $type === 'boolean' => !in_array(strtolower(trim((string) $value)), ['false', '0', 'no', 'non', ''], true),
                $type === 'integer' && is_numeric($value) => (int) $value,
                $type === 'number' && is_numeric($value)  => (float) $value,
                $type === 'string' && !is_string($value)  => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                default => $value,
            };
        }

        return $arguments;
    }

    public function execute(ToolCall $call): ToolResult
    {
        $def = $this->find($call->name);

        if ($def === null) {
            return new ToolResult($call->id, "Tool '{$call->name}' not found.", isError: true);
        }

        try {
            $result = ($def->handler)(...$this->coerce($def, $call->arguments));
            $content = is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            return new ToolResult($call->id, $content);
        } catch (\Throwable $e) {
            return new ToolResult($call->id, "Error: {$e->getMessage()}", isError: true);
        }
    }
}