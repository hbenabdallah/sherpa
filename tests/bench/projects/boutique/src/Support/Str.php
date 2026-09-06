<?php

namespace Boutique\Support;

final class Str
{
    public static function truncate(string $text, int $length, string $end = '…'): string
    {
        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length) . $end;
    }
}
