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

        // An executable cannot change under its own cache: its key already
        // names the file, its size and its date.
        if (!$debug && \Phar::running(false) === '') {
            $this->dropStaleContainer();
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new ToolboxPass());
    }

    /**
     * One compiled container per place the checkout is seen from: Docker mounts
     * it at /app and the host sees its real path, while both write to the same
     * var/cache — and a compiled container holds absolute paths. Keyed by
     * directory, the two coexist instead of taking turns failing.
     */
    public function getCacheDir(): string
    {
        $binary = \Phar::running(false);
        if ($binary !== '') {
            return self::userCacheDir() . '/' . $this->environment . '-'
                . substr(md5($binary . '|' . @filesize($binary) . '|' . @filemtime($binary)), 0, 12);
        }

        return $this->getProjectDir() . '/var/cache/' . $this->environment . '-' . substr(md5($this->getProjectDir()), 0, 8);
    }

    public function getLogDir(): string
    {
        return \Phar::running(false) !== '' ? self::userCacheDir() . '/log' : parent::getLogDir();
    }

    /**
     * Where the single executable compiles its container: the archive itself is
     * read-only, and a new release, a moved file or a rebuilt one gets its own.
     */
    private static function userCacheDir(): string
    {
        $base = getenv('XDG_CACHE_HOME') ?: (($_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir()) . '/.cache');

        return rtrim($base, '/') . '/sherpa';
    }

    /**
     * Rebuild the compiled container when its sources have moved on. Out of
     * debug Symfony compiles once and never looks again — right for a deployed
     * application, wrong for a tool run out of a git checkout, where a pull
     * boots the previous wiring with the old constructor arguments.
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
