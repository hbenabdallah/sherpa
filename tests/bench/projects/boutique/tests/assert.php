<?php

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(sprintf(
            '%sattendu %s, obtenu %s',
            $message === '' ? '' : $message . ' : ',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}
