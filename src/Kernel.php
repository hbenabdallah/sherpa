<?php

namespace App;

use App\DI\ToolboxPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);

        if (!$debug) {
            $this->dropStaleContainer();
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new ToolboxPass());
    }

    /**
     * Rebuild the compiled container when the source it was compiled from has
     * moved on.
     *
     * Out of debug, Symfony compiles the container once and never looks at its
     * sources again — the right trade for a deployed application, and the wrong
     * one for a tool people run out of a git checkout. Add a constructor
     * argument, pull, and Sherpa boots the previous wiring: at best a
     * TypeError before the first prompt, at worse a service quietly holding the
     * argument that used to be in that position.
     */
    private function dropStaleContainer(): void
    {
        $compiled = $this->getCacheDir() . '/' . $this->getContainerClass() . '.php';
        if (!is_file($compiled)) {
            return;
        }

        $builtAt = filemtime($compiled);

        foreach (['/src', '/config'] as $watched) {
            $path = $this->getProjectDir() . $watched;
            if (!is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if ($file->getMTime() > $builtAt) {
                    // A stale container is still better than refusing to start,
                    // so a cache we cannot remove is not a fatal condition.
                    try {
                        (new Filesystem())->remove($this->getCacheDir());
                    } catch (\Throwable) {
                    }

                    return;
                }
            }
        }
    }
}
