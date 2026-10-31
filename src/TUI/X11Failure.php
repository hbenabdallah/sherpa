<?php

declare(strict_types=1);

namespace App\TUI;

use RuntimeException;

/** The X display refused a request, or stopped answering. */
final class X11Failure extends RuntimeException {}
