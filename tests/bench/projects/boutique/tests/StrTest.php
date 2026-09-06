<?php

use Boutique\Support\Str;

function test_truncate_keeps_short_text(): void
{
    assert_same('court', Str::truncate('court', 10));
}

function test_truncate_cuts_long_text(): void
{
    assert_same('Bonj…', Str::truncate('Bonjour', 4));
}
