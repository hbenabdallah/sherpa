<?php

// Entry point of the single executable (see bin/build-release.php). The
// checkout runs bin/sherpa instead, through symfony/runtime and a .env; the
// executable has neither: settings come from the environment and
// ~/.config/sherpa, and it always runs in prod.

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

// The micro build's default is 128M; a long session's history and a
// documentation index can pass it.
ini_set('memory_limit', '512M');

require dirname(__DIR__) . '/vendor/autoload.php';

// Forced, not defaulted: an APP_ENV=dev left in someone's shell would boot the
// debug container, which writes and rechecks its sources on every start.
foreach (['APP_ENV' => 'prod', 'APP_DEBUG' => '0'] as $name => $value) {
    $_SERVER[$name] = $_ENV[$name] = $value;
}
$_SERVER['APP_SECRET'] ??= $_ENV['APP_SECRET'] ?? 'sherpa';

$application = new Application(new Kernel('prod', false));
$application->setName('Sherpa');
$application->setVersion(App\Version::current());
$application->setDefaultCommand('sherpa', true);

exit($application->run());
