<?php

return [
    'dsn'  => getenv('MAILER_DSN') ?: 'null://null',
    'from' => 'boutique@example.test',
];
