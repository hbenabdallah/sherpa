<?php

declare(strict_types=1);

namespace App\TUI\Input;

/**
 * Raw terminal bytes, cut into keypresses.
 *
 * A read returns whatever the terminal had: one letter, a four-byte emoji, an
 * arrow key as "\e[A", or a whole pasted paragraph at once. Nothing guarantees
 * a read ends on a boundary either — a multibyte character or an escape
 * sequence can be split across two reads — so what cannot be completed yet is
 * handed back, to be prefixed to the next read rather than decoded as garbage.
 */
final class KeyReader
{
    /**
     * @return array{0: list<string>, 1: string} the complete keys, and the
     *                                           bytes left over for next time
     */
    public static function split(string $bytes): array
    {
        $keys = [];
        $i = 0;
        $length = strlen($bytes);

        while ($i < $length) {
            $byte = $bytes[$i];

            if ($byte === "\e") {
                $sequence = self::escape($bytes, $i);
                if ($sequence === null) {
                    return [$keys, substr($bytes, $i)];
                }
                $keys[] = $sequence;
                $i += strlen($sequence);
                continue;
            }

            // A continuation byte (0x80–0xBF) where a character should start,
            // or a byte UTF-8 never uses: the remains of broken input, dropped.
            if ((ord($byte) >= 0x80 && ord($byte) <= 0xBF) || ord($byte) >= 0xF8) {
                $i++;
                continue;
            }

            $size = self::utf8Size(ord($byte));

            // A lead byte promises continuation bytes (0x80–0xBF). One that is
            // not followed by them is broken input — a terminal speaking
            // Latin-1, or half a character left by the line discipline — and
            // it is dropped alone. Taken with the bytes after it, it would
            // swallow whatever key came next, Enter included.
            for ($k = 1; $k < $size && $i + $k < $length; $k++) {
                $next = ord($bytes[$i + $k]);
                if ($next < 0x80 || $next > 0xBF) {
                    $i++;
                    continue 2;
                }
            }

            if ($i + $size > $length) {
                return [$keys, substr($bytes, $i)];
            }

            $keys[] = substr($bytes, $i, $size);
            $i += $size;
        }

        return [$keys, ''];
    }

    /**
     * The escape sequence starting at $i, or null when the bytes stop before
     * it does. CSI (\e[…) runs to its final byte, SS3 (\eO…) is one letter,
     * anything else is Alt+key: two bytes.
     */
    private static function escape(string $bytes, int $i): ?string
    {
        $length = strlen($bytes);
        if ($i + 1 >= $length) {
            return null;
        }

        $introducer = $bytes[$i + 1];

        if ($introducer === '[') {
            for ($j = $i + 2; $j < $length; $j++) {
                $c = ord($bytes[$j]);
                // Parameters and intermediates are 0x20–0x3F; the final byte
                // is 0x40–0x7E and ends the sequence.
                if ($c >= 0x40 && $c <= 0x7E) {
                    return substr($bytes, $i, $j - $i + 1);
                }
            }

            return null;
        }

        if ($introducer === 'O') {
            return $i + 2 < $length ? substr($bytes, $i, 3) : null;
        }

        return substr($bytes, $i, 2);
    }

    private static function utf8Size(int $lead): int
    {
        return match (true) {
            $lead >= 0xF0 => 4,
            $lead >= 0xE0 => 3,
            $lead >= 0xC0 => 2,
            default       => 1,
        };
    }
}
