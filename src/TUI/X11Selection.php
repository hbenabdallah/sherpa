<?php

declare(strict_types=1);

namespace App\TUI;

use Throwable;

/**
 * The CLIPBOARD selection held by Sherpa itself, over the X11 protocol, the
 * way xclip holds it: a machine with neither xclip, xsel nor wl-copy still
 * copies. Under a Wayland desktop DISPLAY reaches Xwayland, and the compositor
 * hands the selection on to Wayland applications.
 *
 * X11 serves a selection on request, so a detached process keeps it until
 * another program takes the clipboard or the display goes away.
 */
final readonly class X11Selection
{
    private const int OP_CREATE_WINDOW = 1;

    private const int OP_CHANGE_PROPERTY = 18;

    private const int OP_INTERN_ATOM = 16;

    private const int OP_SET_SELECTION_OWNER = 22;

    private const int OP_GET_SELECTION_OWNER = 23;

    private const int OP_SEND_EVENT = 25;

    private const int EVENT_PROPERTY_NOTIFY = 28;

    private const int EVENT_SELECTION_CLEAR = 29;

    private const int EVENT_SELECTION_REQUEST = 30;

    private const int EVENT_SELECTION_NOTIFY = 31;

    private const int ATOM_ATOM = 4;

    private const int ATOM_INTEGER = 19;

    private const int ATOM_STRING = 31;

    private const int PROPERTY_CHANGE_MASK = 0x400000;

    private const float TIMEOUT = 5.0;

    /**
     * @param string|null $display   DISPLAY when not given
     * @param string|null $authority the Xauthority file, XAUTHORITY or ~/.Xauthority when not given
     */
    public function __construct(
        private ?string $display = null,
        private ?string $authority = null,
    ) {}

    /** Whether a local X display is there to be asked. */
    public function available(): bool
    {
        return $this->socketPath() !== null && function_exists('pcntl_fork');
    }

    /**
     * Takes the clipboard and leaves a process holding it; false when the
     * display refused, or the text is more than one request can carry.
     */
    public function copy(string $text): bool
    {
        if (!$this->available()) {
            return false;
        }

        $x = $this->connect();
        if ($x === null) {
            return false;
        }

        try {
            if (strlen($text) > $x['maxBytes'] - 64) {
                return false;
            }

            $atoms = $this->atoms($x['socket']);
            $window = $x['idBase'];
            $this->send($x['socket'], pack('CCvVVvvvvvvVVV', self::OP_CREATE_WINDOW, 0, 9, $window, $x['root'], 0, 0, 1, 1, 0, 2, 0, 0x800, self::PROPERTY_CHANGE_MASK));
            $time = $this->serverTime($x['socket'], $window, $atoms['SHERPA_CLIPBOARD']);
            $this->send($x['socket'], pack('CCvVVV', self::OP_SET_SELECTION_OWNER, 0, 4, $window, $atoms['CLIPBOARD'], $time));
            $this->send($x['socket'], pack('CCvV', self::OP_GET_SELECTION_OWNER, 0, 2, $atoms['CLIPBOARD']));
            if (self::long($this->reply($x['socket']), 8) !== $window) {
                return false;
            }
        } catch (X11Failure) {
            fclose($x['socket']);

            return false;
        }

        $holder = $this->detach();
        if ($holder !== true) {
            fclose($x['socket']);

            return $holder === false;
        }

        $this->serve($x['socket'], $atoms, $time, $text);
    }

    private function socketPath(): ?string
    {
        $display = $this->display ?? (getenv('DISPLAY') ?: '');
        if (preg_match('/^(?:unix)?:(\d+)(?:\.\d+)?$/', $display, $m) !== 1) {
            return null;
        }

        $path = "/tmp/.X11-unix/X{$m[1]}";

        return file_exists($path) ? $path : null;
    }

    /** @return array{socket: resource, root: int, idBase: int, maxBytes: int}|null */
    private function connect(): ?array
    {
        $path = $this->socketPath();
        if ($path === null) {
            return null;
        }

        $socket = @stream_socket_client("unix://{$path}", $errno, $error, self::TIMEOUT);
        if ($socket === false) {
            return null;
        }
        stream_set_timeout($socket, (int) self::TIMEOUT);

        [$name, $data] = $this->cookie(substr(basename($path), 1));

        try {
            $this->send($socket, pack('CCvvvvv', 0x6C, 0, 11, 0, strlen($name), strlen($data), 0) . self::pad($name) . self::pad($data));
            $head = $this->read($socket, 8);
            $more = $this->read($socket, self::fields('vlength', $head, 6)['length'] * 4);
            if (ord($head[0]) !== 1) {
                fclose($socket);

                return null;
            }

            $setup = self::fields('VidBase/Vmask/Vmotion/vvendor/vmaxRequest/Cscreens/Cformats', $more, 4);
            $screen = 32 + strlen(self::pad(str_repeat("\0", $setup['vendor']))) + 8 * $setup['formats'];

            return [
                'socket'   => $socket,
                'root'     => self::long($more, $screen),
                'idBase'   => $setup['idBase'],
                'maxBytes' => $setup['maxRequest'] * 4,
            ];
        } catch (X11Failure) {
            fclose($socket);

            return null;
        }
    }

    /**
     * The MIT-MAGIC-COOKIE-1 for this display, or none when no file has one.
     *
     * @return array{0: string, 1: string}
     */
    private function cookie(string $number): array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        $file = $this->authority ?? (getenv('XAUTHORITY') ?: (is_string($home) && $home !== '' ? "{$home}/.Xauthority" : ''));
        $content = $file !== '' && is_readable($file) ? (string) file_get_contents($file) : '';

        try {
            return self::cookieIn($content, $number);
        } catch (X11Failure) {
            return ['', ''];
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function cookieIn(string $content, string $number): array
    {
        $offset = 0;
        while ($offset + 2 <= strlen($content)) {
            $family = self::fields('nvalue', $content, $offset)['value'];
            $offset += 2;
            $fields = [];
            for ($i = 0; $i < 4 && $offset + 2 <= strlen($content); $i++) {
                $length = self::fields('nvalue', $content, $offset)['value'];
                $fields[] = substr($content, $offset + 2, $length);
                $offset += 2 + $length;
            }
            if (count($fields) < 4) {
                break;
            }

            [, $display, $name, $data] = $fields;
            if ($name === 'MIT-MAGIC-COOKIE-1' && ($display === $number || $display === '' || $family === 0xFFFF)) {
                return [$name, $data];
            }
        }

        return ['', ''];
    }

    /**
     * @param resource $socket
     *
     * @return array<string, int>
     */
    private function atoms($socket): array
    {
        $names = ['CLIPBOARD', 'TARGETS', 'TIMESTAMP', 'UTF8_STRING', 'TEXT', 'text/plain;charset=utf-8', 'text/plain', 'SHERPA_CLIPBOARD'];
        foreach ($names as $name) {
            $this->send($socket, pack('CCvvv', self::OP_INTERN_ATOM, 0, 2 + intdiv(strlen($name) + 3, 4), strlen($name), 0) . self::pad($name));
        }

        $atoms = [];
        foreach ($names as $name) {
            $atoms[$name] = self::long($this->reply($socket), 8);
        }

        return $atoms;
    }

    /**
     * A timestamp from the server, as selections are meant to be taken with: the time of a property change.
     *
     * @param resource $socket
     */
    private function serverTime($socket, int $window, int $property): int
    {
        $this->changeProperty($socket, 2, $window, $property, self::ATOM_STRING, 8, '');

        while (true) {
            $event = $this->read($socket, 32);
            $this->failOnError($event);
            if ((ord($event[0]) & 0x7F) === self::EVENT_PROPERTY_NOTIFY && self::long($event, 4) === $window) {
                return self::long($event, 12);
            }
        }
    }

    /**
     * Forks twice so the holder belongs to nobody waiting on it: true in the
     * holder, false in Sherpa once it is running, null when it could not be.
     */
    private function detach(): ?bool
    {
        $child = pcntl_fork();
        if ($child === -1) {
            return null;
        }
        if ($child > 0) {
            pcntl_waitpid($child, $status);

            return false;
        }

        if (function_exists('posix_setsid')) {
            posix_setsid();
        }
        if (pcntl_fork() !== 0) {
            self::vanish();
        }

        foreach ([SIGINT, SIGTERM, SIGHUP, SIGQUIT, SIGTSTP, SIGWINCH, SIGCHLD] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
        foreach ([STDIN, STDOUT, STDERR] as $stream) {
            @fclose($stream);
        }

        return true;
    }

    /**
     * @param resource           $socket
     * @param array<string, int> $atoms
     */
    private function serve($socket, array $atoms, int $time, string $text): never
    {
        try {
            stream_set_timeout($socket, 3600);
            $latin1 = function_exists('mb_convert_encoding') ? mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8') : $text;
            $utf8Targets = [$atoms['UTF8_STRING'], $atoms['TEXT'], $atoms['text/plain;charset=utf-8'], $atoms['text/plain']];

            while (true) {
                $event = @fread($socket, 32);
                if ($event === false || $event === '') {
                    if (stream_get_meta_data($socket)['timed_out']) {
                        continue;
                    }
                    self::vanish();
                }
                while (strlen($event) < 32) {
                    $more = @fread($socket, 32 - strlen($event));
                    if ($more === false || $more === '') {
                        self::vanish();
                    }
                    $event .= $more;
                }

                $code = ord($event[0]) & 0x7F;
                if ($code === 1) {
                    $this->read($socket, self::long($event, 4) * 4);
                    continue;
                }
                if ($code === self::EVENT_SELECTION_CLEAR && self::long($event, 12) === $atoms['CLIPBOARD']) {
                    self::vanish();
                }
                if ($code !== self::EVENT_SELECTION_REQUEST) {
                    continue;
                }

                $request = self::fields('Vtime/Vowner/Vrequestor/Vselection/Vtarget/Vproperty', $event, 4);
                $property = $request['property'] !== 0 ? $request['property'] : $request['target'];
                $target = $request['target'];

                $answered = match (true) {
                    $target === $atoms['TARGETS']   => $this->changeProperty($socket, 0, $request['requestor'], $property, self::ATOM_ATOM, 32, pack('V*', $atoms['TARGETS'], $atoms['TIMESTAMP'], $atoms['UTF8_STRING'], self::ATOM_STRING, $atoms['TEXT'], $atoms['text/plain;charset=utf-8'], $atoms['text/plain'])),
                    $target === $atoms['TIMESTAMP'] => $this->changeProperty($socket, 0, $request['requestor'], $property, self::ATOM_INTEGER, 32, pack('V', $time)),
                    in_array($target, $utf8Targets, true) => $this->changeProperty($socket, 0, $request['requestor'], $property, $atoms['UTF8_STRING'], 8, $text),
                    $target === self::ATOM_STRING   => $this->changeProperty($socket, 0, $request['requestor'], $property, self::ATOM_STRING, 8, $latin1),
                    default                         => false,
                };

                $notify = pack('CCvVVVVV', self::EVENT_SELECTION_NOTIFY, 0, 0, $request['time'], $request['requestor'], $request['selection'], $target, $answered ? $property : 0) . str_repeat("\0", 8);
                $this->send($socket, pack('CCvVV', self::OP_SEND_EVENT, 0, 11, $request['requestor'], 0) . $notify);
            }
        } catch (Throwable) {
            self::vanish();
        }
    }

    /**
     * @param resource $socket
     */
    private function changeProperty($socket, int $mode, int $window, int $property, int $type, int $format, string $data): bool
    {
        $units = $format === 32 ? intdiv(strlen($data), 4) : strlen($data);
        $padded = self::pad($data);
        $this->send($socket, pack('CCvVVVCCCCV', self::OP_CHANGE_PROPERTY, $mode, 6 + intdiv(strlen($padded), 4), $window, $property, $type, $format, 0, 0, 0, $units) . $padded);

        return true;
    }

    /**
     * The next reply, past the events that come before it.
     *
     * @param resource $socket
     */
    private function reply($socket): string
    {
        while (true) {
            $packet = $this->read($socket, 32);
            $this->failOnError($packet);
            if (ord($packet[0]) === 1) {
                $extra = self::long($packet, 4) * 4;

                return $extra > 0 ? $packet . $this->read($socket, $extra) : $packet;
            }
        }
    }

    private function failOnError(string $packet): void
    {
        if (ord($packet[0]) === 0) {
            throw new X11Failure('X11 error ' . ord($packet[1]));
        }
    }

    /**
     * @param resource $socket
     */
    private function send($socket, string $bytes): void
    {
        while ($bytes !== '') {
            $written = @fwrite($socket, $bytes);
            if ($written === false || $written === 0) {
                throw new X11Failure('the display stopped listening');
            }
            $bytes = substr($bytes, $written);
        }
    }

    /**
     * @param resource $socket
     */
    private function read($socket, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = @fread($socket, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new X11Failure('the display did not answer');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    private static function long(string $bytes, int $offset): int
    {
        return self::fields('Vvalue', $bytes, $offset)['value'];
    }

    /**
     * Named integers read from a packet, every format code naming its field.
     *
     * @return array<string, int>
     */
    private static function fields(string $format, string $bytes, int $offset): array
    {
        $fields = strlen($bytes) > $offset ? @unpack($format, $bytes, $offset) : false;
        if (!is_array($fields)) {
            throw new X11Failure('a packet ended early');
        }

        return array_map(intval(...), $fields);
    }

    private static function pad(string $bytes): string
    {
        return $bytes . str_repeat("\0", (4 - strlen($bytes) % 4) % 4);
    }

    /** Gone without running what Sherpa set up to run at exit: its terminal and its files are not the holder's. */
    private static function vanish(): never
    {
        posix_kill(posix_getpid(), SIGKILL);

        exit(0);
    }
}
