<?php

declare(strict_types=1);

namespace App\DI;

use App\Agent\Tool\AsTool;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ToolboxPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;

            if (!class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);

            if (!empty($ref->getAttributes(AsTool::class))) {
                $definition->addTag('agent.tool');
                $definition->setPublic(true);
            }
        }
    }
}
