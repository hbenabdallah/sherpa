<?php

declare(strict_types=1);

return [
    'driver'  => 'pgsql',
    'url'     => getenv('DATABASE_URL') ?: null,
    'pool'    => ['min' => 1, 'max' => 10],
    'timeout' => 5,
];
