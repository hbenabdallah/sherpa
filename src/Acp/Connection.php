<?php

namespace App\Acp;

use Closure;
use stdClass;

/**
 * JSON-RPC 2.0 over a pair of streams, one message per line: ACP's stdio
 * transport. The editor launches Sherpa and holds both ends.
 *
 * Single-threaded, so nothing is read unless something asks: receive() waits
 * for the next request, request() for the answer to one of Sherpa's own, and
 * pump() takes whatever has arrived without waiting — what a long turn calls,
 * through the interrupt, to hear a cancel. A message that cannot wait for the
 * turn to end goes to the intercept as it arrives; the rest queue.
 *
 * Nothing but messages may go out on this stream: one stray byte and the
 * editor loses the framing. Whatever else Sherpa prints goes elsewhere (see
 * AcpCommand).
 */
final class Connection
{
    private const READ_BYTES = 65536;

    /** Read, and not yet a whole line. */
    private string $partial = '';

    /** @var list<array<string, mixed>> requests and notifications, oldest first */
    private array $inbox = [];

    /** @var array<int|string, array<string, mixed>> answers to Sherpa's requests, by id */
    private array $replies = [];

    private int $lastId = 0;

    private bool $ended = false;

    /** @var (Closure(array<string, mixed>): bool)|null */
    private ?Closure $intercept = null;

    /**
     * @param resource $in
     * @param resource $out
     */
    public function __construct(private $in, private $out)
    {
        stream_set_blocking($this->in, false);
    }

    /**
     * Sees every request and notification as it arrives, before the queue:
     * returns true for one it dealt with, which then goes no further.
     *
     * @param (Closure(array<string, mixed>): bool)|null $intercept
     */
    public function intercept(?Closure $intercept): void
    {
        $this->intercept = $intercept;
    }

    /**
     * The next request or notification, waiting for it. Null once the editor
     * has closed its end and every message is taken, or when $timeout passes.
     *
     * @return array<string, mixed>|null
     */
    public function receive(?float $timeout = null): ?array
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        while ($this->inbox === []) {
            if ($this->ended) {
                return null;
            }

            $left = $deadline === null ? null : $deadline - microtime(true);
            if ($left !== null && $left <= 0) {
                return null;
            }

            $this->read($left);
        }

        return array_shift($this->inbox);
    }

    /** Take in whatever has arrived, without waiting. */
    public function pump(): void
    {
        $this->read(0.0);
    }

    public function ended(): bool
    {
        return $this->ended && $this->inbox === [];
    }

    /**
     * Ask the editor something and wait for its answer: a permission, a file.
     * What arrives meanwhile goes through the intercept, or queues.
     *
     * @param array<string, mixed> $params
     *
     * @throws AcpError the editor's error, or its end closing first
     */
    public function request(string $method, array $params): mixed
    {
        $id = ++$this->lastId;
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => self::object($params)]);

        while (!array_key_exists($id, $this->replies)) {
            if ($this->ended) {
                throw new AcpError("The editor closed the connection before answering {$method}.");
            }

            $this->read(null);
        }

        $reply = $this->replies[$id];
        unset($this->replies[$id]);

        if (isset($reply['error'])) {
            $error = is_array($reply['error']) ? $reply['error'] : [];

            throw new AcpError((string) ($error['message'] ?? "{$method} failed"), (int) ($error['code'] ?? AcpError::INTERNAL_ERROR), $error['data'] ?? null);
        }

        return $reply['result'] ?? null;
    }

    /** @param array<string, mixed> $params */
    public function notify(string $method, array $params): void
    {
        $this->send(['jsonrpc' => '2.0', 'method' => $method, 'params' => self::object($params)]);
    }

    /** @param array<string, mixed>|null $result */
    public function respond(int|string $id, ?array $result): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === null ? null : self::object($result)]);
    }

    public function fail(int|string|null $id, AcpError $error): void
    {
        $body = ['code' => $error->getCode(), 'message' => $error->getMessage()];
        if ($error->data !== null) {
            $body['data'] = $error->data;
        }

        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => $body]);
    }

    /**
     * An empty PHP array is a JSON list; a params or result object must stay
     * an object even with nothing in it.
     *
     * @param array<string, mixed> $value
     */
    private static function object(array $value): array|stdClass
    {
        return $value === [] ? new stdClass() : $value;
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;
        $line = json_encode($message, $flags);

        // Too deep, or a value JSON has no word for: said as an error to the
        // same id rather than left unanswered.
        if ($line === false) {
            $line = (string) json_encode([
                'jsonrpc' => '2.0',
                'id'      => $message['id'] ?? null,
                'error'   => ['code' => AcpError::INTERNAL_ERROR, 'message' => 'Could not encode the message: ' . json_last_error_msg()],
            ], $flags);
        }

        // json_encode escapes every newline inside a string: this one ends the message.
        $line .= "\n";

        for ($written = 0, $length = strlen($line); $written < $length; $written += $count) {
            $count = @fwrite($this->out, substr($line, $written));
            if ($count === false || $count === 0) {
                // Nobody reads any more: there is no one left to answer.
                $this->ended = true;

                return;
            }
        }

        fflush($this->out);
    }

    /** Read what has arrived, waiting up to $timeout seconds for something (null: as long as it takes). */
    private function read(?float $timeout): void
    {
        $read = [$this->in];
        $write = $except = null;
        $seconds = $timeout === null ? null : (int) $timeout;
        $micro = $timeout === null ? 0 : (int) (($timeout - (int) $timeout) * 1_000_000);

        // False when a signal cut the wait short: nothing read, ask again.
        if (@stream_select($read, $write, $except, $seconds, $micro) !== 1) {
            return;
        }

        $bytes = fread($this->in, self::READ_BYTES);
        if ($bytes === false || $bytes === '') {
            if (feof($this->in)) {
                $this->ended = true;
                // A last message the editor did not end with a newline.
                $this->take($this->partial);
                $this->partial = '';
            }

            return;
        }

        $this->partial .= $bytes;
        while (($end = strpos($this->partial, "\n")) !== false) {
            $line = substr($this->partial, 0, $end);
            $this->partial = substr($this->partial, $end + 1);
            $this->take($line);
        }
    }

    private function take(string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        $message = json_decode($line, true);
        if (!is_array($message) || array_is_list($message)) {
            $this->fail(null, new AcpError('Not a JSON-RPC message: ' . mb_strimwidth($line, 0, 80, '…'), AcpError::PARSE_ERROR));

            return;
        }

        // An answer to one of Sherpa's requests: whoever asked is waiting for it.
        if (!isset($message['method'])) {
            if (isset($message['id']) && (is_int($message['id']) || is_string($message['id']))) {
                $this->replies[$message['id']] = $message;
            }

            return;
        }

        if (!is_string($message['method'])) {
            $this->fail(
                is_int($message['id'] ?? null) || is_string($message['id'] ?? null) ? $message['id'] : null,
                new AcpError('The method must be a string.', AcpError::INVALID_REQUEST),
            );

            return;
        }

        if ($this->intercept !== null && ($this->intercept)($message)) {
            return;
        }

        $this->inbox[] = $message;
    }
}
