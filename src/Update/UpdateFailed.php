<?php

declare(strict_types=1);

namespace App\Update;

/** Why an update did not happen; the executable is as it was. */
final class UpdateFailed extends \RuntimeException
{
}
