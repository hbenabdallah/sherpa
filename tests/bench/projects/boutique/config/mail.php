<?php

declare(strict_types=1);

return [
    'dsn'  => getenv('MAILER_DSN') ?: 'null://null',
    'from' => 'boutique@example.test',
];
