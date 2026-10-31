<?php

declare(strict_types=1);

namespace App\Tests\ClipboardTest;

require __DIR__ . '/../vendor/autoload.php';

use App\TUI\Clipboard;
use App\TUI\X11Selection;
use Symfony\Component\Process\Process;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 220), "\n";
    }
}

/**
 * What another X client gets when it pastes: the CLIPBOARD selection converted
 * to $target, read the way xclip -o reads it.
 *
 * @return array{type: string, value: string}|null
 */
function paste(string $number, string $target): ?array
{
    $socket = stream_socket_client("unix:///tmp/.X11-unix/X{$number}", $errno, $error, 2);
    if ($socket === false) {
        return null;
    }
    stream_set_timeout($socket, 3);

    $read = static function (int $length) use ($socket): string {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($socket, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('no answer');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    };
    $pad = static fn(string $s): string => $s . str_repeat("\0", (4 - strlen($s) % 4) % 4);
    $reply = static function () use ($read): string {
        while (true) {
            $packet = $read(32);
            if (ord($packet[0]) === 0) {
                throw new \RuntimeException('X error ' . ord($packet[1]));
            }
            if (ord($packet[0]) === 1) {
                $extra = unpack('V', $packet, 4)[1] * 4;

                return $packet . ($extra > 0 ? $read($extra) : '');
            }
        }
    };
    $atom = static function (string $name) use ($socket, $pad, $reply): int {
        fwrite($socket, pack('CCvvv', 16, 0, 2 + intdiv(strlen($name) + 3, 4), strlen($name), 0) . $pad($name));

        return unpack('V', $reply(), 8)[1];
    };
    $name = static function (int $atom) use ($socket, $reply): string {
        fwrite($socket, pack('CCvV', 17, 0, 2, $atom));
        $packet = $reply();

        return substr($packet, 32, unpack('v', $packet, 8)[1]);
    };

    try {
        fwrite($socket, pack('CCvvvvv', 0x6C, 0, 11, 0, 0, 0, 0));
        $head = $read(8);
        $more = $read(unpack('v', $head, 6)[1] * 4);
        if (ord($head[0]) !== 1) {
            return null;
        }
        $setup = unpack('VidBase/Vmask/Vmotion/vvendor/vmaxRequest/Cscreens/Cformats', $more, 4);
        $root = unpack('V', $more, 32 + $setup['vendor'] + (4 - $setup['vendor'] % 4) % 4 + 8 * $setup['formats'])[1];
        $window = $setup['idBase'];
        fwrite($socket, pack('CCvVVvvvvvvVV', 1, 0, 8, $window, $root, 0, 0, 1, 1, 0, 2, 0, 0));

        $clipboard = $atom('CLIPBOARD');
        $wanted = $atom($target);
        $into = $atom('SHERPA_TEST_PASTE');
        fwrite($socket, pack('CCvVVVVV', 24, 0, 6, $window, $clipboard, $wanted, $into, 0));

        while (true) {
            $event = $read(32);
            if ((ord($event[0]) & 0x7F) === 31) {
                break;
            }
        }
        if (unpack('V', $event, 20)[1] === 0) {
            return ['type' => '', 'value' => ''];
        }

        fwrite($socket, pack('CCvVVVVV', 20, 1, 6, $window, $into, 0, 0, 1 << 20));
        $packet = $reply();
        $format = ord($packet[1]);
        $type = unpack('V', $packet, 8)[1];
        $length = unpack('V', $packet, 16)[1] * intdiv($format, 8);

        return ['type' => $name($type), 'value' => substr($packet, 32, $length)];
    } catch (\RuntimeException) {
        return null;
    } finally {
        fclose($socket);
    }
}

// ---- without a display: nothing to ask ------------------------------------

$none = new X11Selection(display: '');
check('no DISPLAY: not available', !$none->available());
check('no DISPLAY: nothing copied', !$none->copy('x'));
$remote = new X11Selection(display: 'localhost:10.0');
check('a remote DISPLAY (SSH forwarding) is not taken for a local one', !$remote->available());

$clipboard = new Clipboard(which: static fn(string $command): ?string => null, x11: $none);
check('no tool, no display, and 80,000 characters: past OSC 52 once in base64, nothing copies', $clipboard->copy(str_repeat('x', 80_000)) === null);

// ---- with a display: another client pastes what was copied -----------------

$xvfb = trim((string) shell_exec('command -v Xvfb 2>/dev/null'));
if ($xvfb === '') {
    echo " SKIP the X11 cases: no Xvfb here\n";
} else {
    $number = (string) (90 + getmypid() % 50);
    $server = new Process([$xvfb, ":{$number}", '-nolisten', 'tcp']);
    $server->start();
    for ($i = 0; $i < 50 && !file_exists("/tmp/.X11-unix/X{$number}"); $i++) {
        usleep(100_000);
    }

    try {
        $x11 = new X11Selection(display: ":{$number}", authority: '/nonexistent');
        check('a local DISPLAY is available', $x11->available());

        $text = "Réponse en **Markdown**\n- é, ü, 日本語\n";
        check('the clipboard is taken', $x11->copy($text));
        $utf8 = paste($number, 'UTF8_STRING');
        check('pasted as UTF8_STRING, byte for byte', $utf8 === ['type' => 'UTF8_STRING', 'value' => $text], var_export($utf8, true));
        $plain = paste($number, 'text/plain;charset=utf-8');
        check('pasted as text/plain;charset=utf-8', ($plain['value'] ?? null) === $text, var_export($plain, true));
        $string = paste($number, 'STRING');
        check('pasted as STRING, in Latin-1', ($string['value'] ?? null) === mb_convert_encoding("Réponse en **Markdown**\n- é, ü, ???\n", 'ISO-8859-1', 'UTF-8'), var_export($string, true));
        $targets = paste($number, 'TARGETS');
        check('TARGETS answered with atoms', ($targets['type'] ?? null) === 'ATOM' && strlen($targets['value']) === 28, var_export($targets, true));
        check('an unknown target is refused', paste($number, 'image/png') === ['type' => '', 'value' => '']);

        check('a second copy takes it over', $x11->copy('second'));
        check('the second text is what pastes', (paste($number, 'UTF8_STRING')['value'] ?? null) === 'second');

        $clipboard = new Clipboard(which: static fn(string $command): ?string => null, x11: $x11);
        check('Clipboard uses it when no tool is installed', $clipboard->copy('third') === 'the X11 clipboard');
        check('and that is what pastes', (paste($number, 'UTF8_STRING')['value'] ?? null) === 'third');

        $garbled = tempnam(sys_get_temp_dir(), 'xauth');
        file_put_contents($garbled, "\x01\x00\x00\x09short");
        check('a garbled Xauthority is read as no cookie, not a crash', new X11Selection(display: ":{$number}", authority: $garbled)->copy('fourth'));
        unlink($garbled);

        check('a text past one request is not taken', !$x11->copy(str_repeat('a', 300_000)));
    } finally {
        $server->stop(1);
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
