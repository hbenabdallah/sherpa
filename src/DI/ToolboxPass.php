<?php

declare(strict_types=1);

namespace App\DI;

use App\Agent\Tool\AsTool;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ToolboxPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;

            if (!class_exists($class)) {
                continue;
            }

            try {
                $ref = new \ReflectionClass($class);
            } catch (\ReflectionException) {
                continue;
            }

            if (!empty($ref->getAttributes(AsTool::class))) {
                $definition->addTag('agent.tool');
                $definition->setPublic(true);
            }
        }
    }
}
