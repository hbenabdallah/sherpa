<?php

declare(strict_types=1);

namespace App\Platform;

use App\Runtime\Interrupt;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Any backend that speaks OpenAI's /chat/completions: one class, since that
 * shape is the lingua franca, so a backend is a URL, a key and a model name
 * rather than a class to write. Ollama keeps its own for what this dialect
 * lacks — num_ctx, keep_alive, residency, durations.
 *
 * A 400 naming a parameter is read as the server describing itself, and the
 * request is retried without it for the session: a hardcoded list of which
 * models are fussy would be wrong within the month.
 *
 * Anthropic's own API speaks another dialect, chosen by address: the wire
 * format is in AnthropicWire and AnthropicStream, the transport stays here.
 */
final class OpenAiCompatiblePlatform implements PlatformInterface, EmbeddingBackend
{
    /** Liveness and catalogue probes: they answer at once or not at all. */
    private const PROBE_TIMEOUT = 10;

    /** Texts per /embeddings request. */
    private const EMBED_BATCH = 32;

    /** How often to surface control while the server is silent — as Ollama. */
    private const POLL_SECONDS = 0.5;

    /** Attempts for a request the server said to retry (429, 502, 503, 504). */
    private const MAX_ATTEMPTS = 3;

    /** Longest wait between those attempts, whatever Retry-After asks for. */
    private const MAX_BACKOFF_SECONDS = 20;

    /** @var array{prompt: int, completion: int}|null */
    private ?array $lastUsage = null;

    /** @var array{prefill: float, decode: float}|null */
    private ?array $lastTimings = null;

    /**
     * What this particular server turned out to accept. Learned from its own
     * rejections, once per session, rather than configured per provider.
     */
    private bool $sendsTemperature = true;
    private bool $sendsStreamOptions = true;
    private string $maxTokensField = 'max_tokens';

    /** A provider's own key, over the environment's; null to use that one. */
    private ?string $keyOverride = null;

    /** @var array<int, array<string, mixed>> the agent's last request, to notice a rewritten history */
    private array $previousTurn = [];

    /** Messages before this index have lost their thinking blocks. */
    private int $keepThinkingFrom = 0;

    public function __construct(
        private readonly HttpClientInterface $client,
        /** Up to and including the version segment: https://api.example.com/v1 */
        private string $baseUrl,
        private string $model,
        /**
         * A bearer token, empty for a server that wants none. Read from the
         * environment by the caller, never from a config file.
         */
        private readonly string $apiKey = '',
        /**
         * The window Sherpa budgets against: its own ceiling on what it sends,
         * and on a paid backend a spending decision, since every turn resends
         * the whole conversation.
         */
        private int $contextWindow = 32768,
        /**
         * Idle timeout in seconds, negative for unlimited. Generous: a
         * reasoning model can think in silence for minutes before its first token.
         */
        private readonly float $timeout = 300.0,
        private readonly ?Interrupt $interrupt = null,
        private readonly float $temperature = 0.15,
        private readonly int $maxTokens = 4096,
        /** What to call this backend in a message; the host, by default. */
        private readonly ?string $label = null,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public function stream(array $messages, array $tools = [], ?callable $onToken = null, ?callable $onWait = null): array
    {
        $this->lastUsage = null;
        $this->lastTimings = null;

        if ($this->isAnthropic()) {
            return $this->streamAnthropic($messages, $tools, $onToken, $onWait);
        }

        $payload = [
            'model'    => $this->model,
            'messages' => $this->wireMessages($messages),
            'stream'   => true,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        return $this->attempt($payload, $onToken, $onWait, negotiations: 0);
    }

    /**
     * One request, retried where the server says the parameters or the moment
     * were wrong.
     *
     * @param array<string, mixed>          $payload
     * @param (callable(string): void)|null $onToken
     * @param (callable(bool): void)|null   $onWait
     *
     * @return array<string, mixed>
     */
    private function attempt(array $payload, ?callable $onToken, ?callable $onWait, int $negotiations): array
    {
        $payload = $this->withNegotiatedParameters($payload);

        for ($try = 1; ; $try++) {
            $startedAt = microtime(true);
            $response = $this->send($payload, onWait: $onWait);
            if ($this->interrupt?->requested()) {
                return ['role' => 'assistant', 'content' => ''];
            }
            $status = $this->statusOf($response);

            if ($status >= 400) {
                $body = (string) $response->getContent(throw: false);
                $response->cancel();

                // The server describing its own dialect. Retried once per
                // parameter, and only while nothing has been streamed yet.
                if ($status === 400 && $negotiations < 3 && $this->negotiate($body)) {
                    return $this->attempt($payload, $onToken, $onWait, $negotiations + 1);
                }

                throw new \RuntimeException($this->explain($status, $body));
            }

            [$forward, $shown] = $this->watching($onToken);

            try {
                return $this->consume($response, $forward, $onWait, $startedAt);
            } catch (StreamFailed $e) {
                $this->waitBeforeRetry($e, $shown(), $try);
            }
        }
    }

    /**
     * $onToken, and a way to ask afterwards whether anything went through it.
     *
     * @param (callable(string): void)|null $onToken
     *
     * @return array{0: (callable(string): void)|null, 1: callable(): bool}
     */
    private function watching(?callable $onToken): array
    {
        $shown = false;
        $forward = $onToken === null ? null : function (string $token) use ($onToken, &$shown): void {
            $shown = true;
            $onToken($token);
        };

        return [$forward, function () use (&$shown): bool {
            return $shown;
        }];
    }

    /**
     * A stream the server gave up on is asked again — the same patience as
     * for a 503 — while it was a passing condition and nothing reached the
     * screen; a second reply would print after the first half.
     */
    private function waitBeforeRetry(StreamFailed $e, bool $shown, int $try): void
    {
        if (!$e->retryable || $shown || $try >= self::MAX_ATTEMPTS || $this->interrupt?->requested()) {
            throw $e;
        }

        sleep(min(self::MAX_BACKOFF_SECONDS, 2 ** $try));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withNegotiatedParameters(array $payload): array
    {
        unset(
            $payload['temperature'],
            $payload['max_tokens'],
            $payload['max_completion_tokens'],
            $payload['stream_options'],
        );

        if ($this->sendsTemperature) {
            $payload['temperature'] = $this->temperature;
        }

        // Without it a streamed reply reports no token counts at all, and
        // ContextBudget spends the session on an uncalibrated estimate. Worth
        // asking for, not worth failing over: a server that does not know the
        // parameter drops it below and Sherpa carries on estimating.
        if ($this->sendsStreamOptions) {
            $payload['stream_options'] = ['include_usage' => true];
        }

        $payload[$this->maxTokensField] = $this->maxTokens;

        return $payload;
    }

    /**
     * Read a 400 as a statement about this server, and adjust if it is one.
     * Deliberately literal — anything else is a real error, reported as one.
     */
    private function negotiate(string $body): bool
    {
        $body = strtolower($body);

        $rejected = static fn(string $parameter): bool => str_contains($body, $parameter)
            && (str_contains($body, 'unsupported') || str_contains($body, 'not supported')
                || str_contains($body, 'unrecognized') || str_contains($body, 'unknown parameter'));

        if ($this->maxTokensField === 'max_tokens' && $rejected('max_tokens')) {
            $this->maxTokensField = 'max_completion_tokens';

            return true;
        }

        if ($this->sendsTemperature && $rejected('temperature')) {
            $this->sendsTemperature = false;

            return true;
        }

        if ($this->sendsStreamOptions && $rejected('stream_options')) {
            $this->sendsStreamOptions = false;

            return true;
        }

        return false;
    }

    /**
     * Send, waiting out the delays the server asks for. A rate limit or a
     * gateway hiccup is the one failure worth retrying by itself; anything else
     * would only repeat, at the same price.
     *
     * @param array<string, mixed> $payload
     */
    private function send(array $payload, string $path = '/chat/completions', string $accept = 'text/event-stream', ?callable $onWait = null): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        if ($this->baseUrl === '') {
            throw new \RuntimeException('No API address configured: set SHERPA_API_URL.');
        }

        $headers = $this->headers($accept);

        for ($attempt = 1; ; $attempt++) {
            $response = $this->client->request('POST', $this->baseUrl . $path, [
                'headers' => $headers,
                'json'    => $payload,
                'timeout' => $this->timeout,
            ]);

            if (!$this->awaitHeaders($response, $onWait)) {
                return $response;
            }

            $status = $this->statusOf($response);

            if (!in_array($status, [429, 502, 503, 504], true) || $attempt >= self::MAX_ATTEMPTS) {
                return $response;
            }

            $wait = $this->retryAfter($response, $attempt);
            $response->cancel();

            if ($this->interrupt?->requested()) {
                return $response;
            }

            sleep($wait);
        }
    }

    private function retryAfter(\Symfony\Contracts\HttpClient\ResponseInterface $response, int $attempt): int
    {
        $header = $response->getHeaders(throw: false)['retry-after'][0] ?? null;

        $asked = is_numeric($header) ? (int) $header : 2 ** $attempt;

        return max(1, min(self::MAX_BACKOFF_SECONDS, $asked));
    }

    /**
     * Wait for the response to begin, half a second at a time. Many servers
     * send nothing, headers included, until they have read the whole prompt:
     * seconds, during which the status line would block — the spinner frozen
     * and Ctrl+C unheard.
     *
     * @return bool false when the user cut it short
     */
    private function awaitHeaders(\Symfony\Contracts\HttpClient\ResponseInterface $response, ?callable $onWait): bool
    {
        while (true) {
            try {
                foreach ($this->client->stream($response, self::POLL_SECONDS) as $chunk) {
                    if ($chunk->isTimeout()) {
                        if ($onWait !== null) {
                            $onWait(false);
                        }
                        break;
                    }

                    // The first chunk is the headers; the body is read later.
                    return true;
                }
            } catch (\Throwable) {
                return true; // a transport failure, which the status reports
            }

            if ($this->interrupt?->requested()) {
                $response->cancel();

                return false;
            }
        }
    }

    /** A transport failure reads as a status of 0 rather than as an exception. */
    private function statusOf(\Symfony\Contracts\HttpClient\ResponseInterface $response): int
    {
        try {
            return $response->getStatusCode();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Whatever the provider said, with the part a person can act on first. The
     * key never appears: this string ends up in bug reports.
     */
    private function explain(int $status, string $body, ?string $model = null): string
    {
        $decoded = json_decode($body, true);
        // OpenAI's shape, a bare message, or Cloudflare's list of errors.
        $message = is_array($decoded)
            ? ($decoded['error']['message'] ?? $decoded['errors'][0]['message'] ?? $decoded['message'] ?? null)
            : null;

        $detail = is_string($message) && $message !== ''
            ? $message
            : trim(mb_substr($body, 0, 300));

        // 403 is not "bad key" everywhere: Cloudflare answers it for a model
        // the account's plan does not include. The provider's own sentence,
        // quoted after, is what says which.
        $prefix = match (true) {
            $status === 401 => 'Key refused by ' . $this->name(),
            $status === 403 => 'Access refused by ' . $this->name(),
            $status === 404 => 'Model or address unknown to ' . $this->name(),
            $status === 429 => 'Quota or rate exceeded on ' . $this->name(),
            $status >= 500  => 'Failure on ' . $this->name() . "'s side",
            $status === 0   => $this->name() . ' unreachable',
            default         => 'Request refused by ' . $this->name(),
        };

        $message = $detail === '' ? "{$prefix} (HTTP {$status})." : "{$prefix} (HTTP {$status}) : {$detail}";

        return $message . $this->modelHint($detail, $model ?? $this->model);
    }

    /**
     * What to do when the provider has never heard of the model. The id is the
     * provider's own (`gpt-4o` here, `@cf/zai-org/glm-4.7-flash` there) and
     * cannot always be checked at startup — Cloudflare answers 405 to the
     * catalogue — so the mistake surfaces at the first real request.
     */
    private function modelHint(string $detail, string $model): string
    {
        $says = strtolower($detail);

        $aboutTheModel = str_contains($says, 'no such model')
            || str_contains($says, 'model_not_found')
            || str_contains($says, 'does not exist')
            || (str_contains($says, 'model') && str_contains($says, strtolower($model)));

        if (!$aboutTheModel) {
            return '';
        }

        return "\n  The model in force is \"{$model}\" — that id is the provider's own,"
            . "\n  not a common name. Correct it with /model.";
    }

    /**
     * Read the event stream to its end, or to the user's Ctrl+C. Polled as in
     * Ollama's class: nothing arrives while the server thinks, which is exactly
     * when someone reaches for Ctrl+C.
     *
     * @param (callable(string): void)|null $onToken
     *
     * @return array<string, mixed>
     */
    private function consume(
        \Symfony\Contracts\HttpClient\ResponseInterface $response,
        ?callable $onToken,
        ?callable $onWait,
        float $startedAt,
    ): array {
        $content = '';
        $toolCalls = [];
        $firstTokenAt = null;

        $completed = $this->pump($response, $onWait, function (string $line) use (&$content, &$toolCalls, $onToken, &$firstTokenAt, $onWait): void {
            $this->consumeLine($line, $content, $toolCalls, $onToken, $firstTokenAt, $onWait);
        });

        if ($completed) {
            $this->lastTimings = $this->timings($startedAt, $firstTokenAt);
        }

        $message = ['role' => 'assistant', 'content' => $content];
        if ($toolCalls !== []) {
            $message['tool_calls'] = $this->finishToolCalls($toolCalls);
        }

        return $message;
    }

    /**
     * Hand each line of the stream to $onLine, surfacing every half second of
     * silence to $onWait and stopping at Ctrl+C.
     *
     * @param callable(string): void $onLine
     *
     * @return bool false when the user cut it short
     */
    private function pump(\Symfony\Contracts\HttpClient\ResponseInterface $response, ?callable $onWait, callable $onLine): bool
    {
        $buffer = '';
        $silence = true;

        while ($silence) {
            $silence = false;

            foreach ($this->client->stream($response, self::POLL_SECONDS) as $chunk) {
                if ($chunk->isTimeout()) {
                    $silence = true;
                    if ($onWait !== null) {
                        $onWait(false);
                    }
                    break;
                }

                if ($this->interrupt?->requested()) {
                    break;
                }

                $buffer .= $chunk->getContent();
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);

                foreach ($lines as $line) {
                    $onLine($line);
                }
            }

            if ($this->interrupt?->requested()) {
                $response->cancel();

                return false;
            }
        }

        $onLine($buffer);

        return true;
    }

    /**
     * One SSE line: comments (`: keep-alive`) and the closing `[DONE]` are not
     * data; everything else is one JSON chunk of the reply.
     *
     * @param array<int|string, array<string, mixed>> $toolCalls
     * @param (callable(string): void)|null           $onToken
     */
    private function consumeLine(
        string $line,
        string &$content,
        array &$toolCalls,
        ?callable $onToken,
        ?float &$firstTokenAt,
        ?callable $onWait = null,
    ): void {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, ':') || !str_starts_with($line, 'data:')) {
            return;
        }

        $payload = trim(substr($line, 5));
        if ($payload === '' || $payload === '[DONE]') {
            return;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return;
        }

        // A failure after the stream opened comes as data too. Ignored, it
        // reads as an empty reply, and the model gets blamed for it.
        if (isset($data['error']) && !isset($data['choices'])) {
            $error = $data['error'];
            $said = is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error;

            throw StreamFailed::from("{$this->name()} stopped mid-reply: " . ($said !== '' ? $said : 'no reason given'), is_array($error) ? ($error['code'] ?? null) : null);
        }

        // OpenAI sends it once, in a chunk whose choices are empty — hence
        // read first. Cloudflare sends it on every chunk, zeros included, so
        // the largest figure seen is what holds for both.
        if (is_array($data['usage'] ?? null)) {
            $usage = $data['usage'];
            $cached = max(
                $this->lastUsage['cached'] ?? 0,
                (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
            );

            $this->lastUsage = [
                'prompt'     => max($this->lastUsage['prompt'] ?? 0, (int) ($usage['prompt_tokens'] ?? 0)),
                'completion' => max($this->lastUsage['completion'] ?? 0, (int) ($usage['completion_tokens'] ?? 0)),
            ];

            // Only when there is something to say: a provider's cache is what
            // makes a long session cheaper, and zero is not news.
            if ($cached > 0) {
                $this->lastUsage['cached'] = $cached;
            }
        }

        $delta = $data['choices'][0]['delta'] ?? [];
        if (!is_array($delta)) {
            return;
        }

        // A reasoning model thinks out loud before answering, in a field of its
        // own that is not shown. That is generation already, so it ends the
        // prompt-reading wait; counting it as prefill would make a fast server
        // look slow in proportion to how hard it thought.
        foreach (['reasoning', 'reasoning_content'] as $field) {
            if (is_string($delta[$field] ?? null) && $delta[$field] !== '') {
                $firstTokenAt ??= microtime(true);

                if ($onWait !== null) {
                    $onWait(true);
                }

                break;
            }
        }

        if (!empty($delta['tool_calls']) && is_array($delta['tool_calls'])) {
            $firstTokenAt ??= microtime(true);
            $this->accumulateToolCalls($toolCalls, $delta['tool_calls']);
        }

        $text = $delta['content'] ?? '';
        if (is_string($text) && $text !== '') {
            $firstTokenAt ??= microtime(true);
            $content .= $text;

            if ($onToken !== null) {
                $onToken($text);
            }
        }
    }

    /**
     * Merge streamed tool-call fragments: arguments arrive as JSON text cut at
     * arbitrary points, and the index says which of the calls being streamed a
     * fragment belongs to. The id and name come once, in the opening fragment.
     *
     * @param array<int|string, array<string, mixed>> $acc
     * @param array<int, array<string, mixed>>        $deltas
     */
    private function accumulateToolCalls(array &$acc, array $deltas): void
    {
        foreach ($deltas as $position => $delta) {
            if (!is_array($delta)) {
                continue;
            }

            $index = $delta['index'] ?? $position;
            $function = is_array($delta['function'] ?? null) ? $delta['function'] : [];

            $acc[$index] ??= ['id' => null, 'function' => ['name' => '', 'arguments' => '']];

            if (is_string($delta['id'] ?? null) && $delta['id'] !== '') {
                $acc[$index]['id'] = $delta['id'];
            }
            if (is_string($function['name'] ?? null) && $function['name'] !== '') {
                $acc[$index]['function']['name'] = $function['name'];
            }
            if (is_string($function['arguments'] ?? null)) {
                $acc[$index]['function']['arguments'] .= $function['arguments'];
            }
            // What a provider attaches to a call for itself — Gemini's thought
            // signature — kept whole: it refuses the next request without it.
            if (is_array($delta['extra_content'] ?? null)) {
                $acc[$index]['extra_content'] = array_replace_recursive($acc[$index]['extra_content'] ?? [], $delta['extra_content']);
            }
        }
    }

    /**
     * The accumulated fragments in the shape the interface promises: arguments
     * as an array, in call order, ids as the server issued them since it is the
     * server that checks the results against them. Arguments that never parse
     * keep their text, and AgentLoop settles what to do with them.
     *
     * @param array<int|string, array<string, mixed>> $acc
     *
     * @return array<int, array<string, mixed>>
     */
    private function finishToolCalls(array $acc): array
    {
        ksort($acc);

        $calls = [];

        foreach ($acc as $call) {
            $arguments = $call['function']['arguments'];
            if (is_string($arguments)) {
                $decoded = json_decode($arguments === '' ? '{}' : $arguments, true);
                $call['function']['arguments'] = is_array($decoded) ? $decoded : $arguments;
            }

            if (!is_string($call['id'] ?? null) || $call['id'] === '') {
                unset($call['id']); // AgentLoop issues one; see PlatformInterface.
            }

            $calls[] = $call;
        }

        return $calls;
    }

    /**
     * Throughput, timed here because the server reports none: prefill from the
     * silence before the first token, decode from the streaming stretch. The
     * same two numbers Ollama hands over, answering the same question — whether
     * this backend can afford to summarise rather than elide.
     *
     * @return array{prefill: float, decode: float}|null
     */
    private function timings(float $startedAt, ?float $firstTokenAt): ?array
    {
        if ($this->lastUsage === null || $firstTokenAt === null) {
            return null;
        }

        $waited = $firstTokenAt - $startedAt;
        $streamed = microtime(true) - $firstTokenAt;

        return [
            'prefill' => $waited > 0.0 ? (float) ($this->lastUsage['prompt'] / $waited) : 0.0,
            'decode'  => $streamed > 0.0 ? (float) ($this->lastUsage['completion'] / $streamed) : 0.0,
        ];
    }

    /**
     * The history, in this dialect. Three differences from what Sherpa keeps,
     * all refusals rather than preferences: arguments travel as JSON text, a
     * call declares its type, and a tool result carries nothing but the id of
     * the call it answers — some providers reject `name` outright. And one
     * thing carried through untouched: a call's extra_content, where Gemini
     * puts the thought signature it wants back.
     *
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private function wireMessages(array $messages): array
    {
        $wire = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');

            if ($role === 'tool') {
                $wire[] = [
                    'role'         => 'tool',
                    'tool_call_id' => (string) ($message['tool_call_id'] ?? ''),
                    'content'      => (string) ($message['content'] ?? ''),
                ];

                continue;
            }

            $out = ['role' => $role, 'content' => (string) ($message['content'] ?? '')];

            foreach ($message['tool_calls'] ?? [] as $call) {
                $arguments = $call['function']['arguments'] ?? [];

                $wireCall = [
                    'id'       => (string) ($call['id'] ?? ''),
                    'type'     => 'function',
                    'function' => [
                        'name'      => (string) ($call['function']['name'] ?? ''),
                        'arguments' => is_string($arguments)
                            ? $arguments
                            : (json_encode($arguments === [] ? new \stdClass() : $arguments) ?: '{}'),
                    ],
                ];
                // Handed back as it came, and only by the provider that sent it.
                if (is_array($call['extra_content'] ?? null) && $call['extra_content'] !== []) {
                    $wireCall['extra_content'] = $call['extra_content'];
                }
                $out['tool_calls'][] = $wireCall;
            }

            $wire[] = $out;
        }

        return $wire;
    }

    /**
     * The agent's turns keep a record of what was sent, so that a rewritten
     * history (compaction) drops the thinking blocks it invalidated. Requests
     * with nobody listening are side passes — a summary, the fact extractor —
     * with a conversation of their own, and are not recorded.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     *
     * @return array<string, mixed>
     */
    private function streamAnthropic(array $messages, array $tools, ?callable $onToken, ?callable $onWait): array
    {
        $messages = array_values($messages);
        $keepThinkingFrom = 0;

        if ($onToken !== null) {
            $rewritten = $this->previousTurn === [] ? null : AnthropicWire::rewrittenUpTo($this->previousTurn, $messages);
            if ($rewritten !== null) {
                $this->keepThinkingFrom = $rewritten;
            }
            $this->previousTurn = $messages;
            $keepThinkingFrom = $this->keepThinkingFrom;
        }

        for ($attempt = 1; ; $attempt++) {
            $payload = AnthropicWire::payload($this->model, $messages, $tools, max($this->maxTokens, AnthropicWire::MAX_TOKENS), $keepThinkingFrom);

            $startedAt = microtime(true);
            $response = $this->send($payload, '/messages', onWait: $onWait);
            if ($this->interrupt?->requested()) {
                return ['role' => 'assistant', 'content' => ''];
            }
            $status = $this->statusOf($response);

            if ($status >= 400) {
                $body = (string) $response->getContent(throw: false);
                $response->cancel();

                // Thinking blocks bound to a history that has since changed —
                // a session resumed after a compaction, say. Sent again
                // without them, once: the reply is made without that reasoning.
                if ($status === 400 && $attempt === 1 && AnthropicWire::isStaleThinking($body)) {
                    $keepThinkingFrom = count($messages);
                    if ($onToken !== null) {
                        $this->keepThinkingFrom = $keepThinkingFrom;
                    }

                    continue;
                }

                throw new \RuntimeException($this->explain($status, $body));
            }

            [$forward, $shown] = $this->watching($onToken);
            $stream = new AnthropicStream($forward, $onWait);

            try {
                $completed = $this->pump($response, $onWait, $stream->line(...));
                break;
            } catch (StreamFailed $e) {
                $this->lastUsage = $stream->lastUsage();
                $this->waitBeforeRetry($e, $shown(), $attempt);
            }
        }

        $this->lastUsage = $stream->lastUsage();
        if ($completed) {
            $this->lastTimings = $this->timings($startedAt, $stream->firstTokenAt());
        }

        return $stream->message();
    }

    private function isAnthropic(): bool
    {
        return AnthropicWire::handles($this->baseUrl);
    }

    /** @return array<string, string> */
    private function headers(string $accept): array
    {
        if ($this->isAnthropic()) {
            return AnthropicWire::headers($this->key(), $accept);
        }

        $headers = ['Content-Type' => 'application/json', 'Accept' => $accept];
        if ($this->key() !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->key();
        }

        return $headers;
    }

    public function lastUsage(): ?array
    {
        return $this->lastUsage;
    }

    public function lastTimings(): ?array
    {
        return $this->lastTimings;
    }

    /**
     * Nothing to answer: where the weights sit is the provider's business. Null
     * is the interface's word for that, and every caller handles it already.
     */
    public function residency(): ?ModelResidency
    {
        return null;
    }

    public function modelName(): string
    {
        return $this->model;
    }

    /**
     * The dialect's /embeddings, in batches: a project's documentation is
     * hundreds of chunks, and one request for all of them is one timeout.
     */
    public function embed(array $texts, string $model): array
    {
        if ($this->isAnthropic()) {
            throw new UnknownEmbeddingModel('Anthropic offers no embedding model.');
        }

        $vectors = [];

        foreach (array_chunk($texts, self::EMBED_BATCH) as $batch) {
            $response = $this->send(['model' => $model, 'input' => $batch], '/embeddings', 'application/json');
            $status = $this->statusOf($response);
            $body = (string) $response->getContent(throw: false);

            // 400, 404, 422: the request was well formed, so it is the model
            // this provider does not have — which detection needs to tell apart.
            if (in_array($status, [400, 404, 422], true)) {
                throw new UnknownEmbeddingModel('Embeddings: ' . $this->explain($status, $body, $model));
            }
            if ($status >= 400) {
                throw new \RuntimeException('Embeddings: ' . $this->explain($status, $body, $model));
            }

            $rows = json_decode($body, true)['data'] ?? null;
            if (!is_array($rows) || count($rows) !== count($batch)) {
                throw new \RuntimeException("Embeddings: unexpected answer from {$this->name()} for \"{$model}\".");
            }

            // Answers carry their index; nothing promises they come in order.
            usort($rows, static fn(array $a, array $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
            foreach ($rows as $row) {
                $vectors[] = array_map('floatval', (array) ($row['embedding'] ?? []));
            }
        }

        return $vectors;
    }

    public function contextWindow(): int
    {
        return $this->contextWindow;
    }

    public function name(): string
    {
        if ($this->label !== null) {
            return $this->label;
        }

        return $this->baseUrl === '' ? 'the API' : (parse_url($this->baseUrl, PHP_URL_HOST) ?: $this->baseUrl);
    }

    /** The address requests go to, for telling someone where their code is sent. */
    public function endpoint(): string
    {
        return $this->baseUrl;
    }

    /** Point at another address — one recorded on this machine, or just typed. */
    public function useEndpoint(string $baseUrl): void
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
    }

    public function hasKey(): bool
    {
        return $this->key() !== '';
    }

    /**
     * Use a provider's own key — '' for one that wants none — or, with null,
     * the environment's SHERPA_API_KEY again.
     */
    public function useKey(?string $key): void
    {
        $this->keyOverride = $key !== null ? trim($key) : null;
    }

    public function keyOverride(): ?string
    {
        return $this->keyOverride;
    }

    private function key(): string
    {
        return $this->keyOverride ?? $this->apiKey;
    }

    /**
     * Whether the server answers and accepts the key. /models costs no tokens,
     * but not every server lists its models (Cloudflare answers 405), so any
     * answer counts as "there" but a refused key or a dead server.
     */
    public function isAvailable(): bool
    {
        $response = $this->probe('/models');

        if ($response === null) {
            return false;
        }

        $status = $this->statusOf($response);

        return $status > 0 && $status < 500 && !in_array($status, [401, 403], true);
    }

    /**
     * What this server offers. Usually a bare list of ids, so the two facts
     * that decide a choice come back unknown; a window is read where one is
     * given (`context_length` on OpenRouter, `max_model_len` on vLLM).
     *
     * @return array<int, ModelInfo>
     */
    public function catalogue(): array
    {
        return $this->listing() ?? [];
    }

    /**
     * The server's list, or null when it publishes none — not the same as an
     * empty one, and describeModel() treats the two differently.
     *
     * @return array<int, ModelInfo>|null
     */
    private function listing(): ?array
    {
        // Anthropic pages its list, twenty by default.
        $response = $this->probe($this->isAnthropic() ? '/models?limit=1000' : '/models');

        if ($response === null || $this->statusOf($response) !== 200) {
            return null;
        }

        try {
            $data = $response->toArray(throw: false);
        } catch (\Throwable) {
            return null;
        }

        $models = [];

        foreach ($data['data'] ?? [] as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null)) {
                continue;
            }

            $models[] = $this->describeEntry($entry);
        }

        usort($models, fn(ModelInfo $a, ModelInfo $b) => strcmp($a->name, $b->name));

        return $models;
    }

    public function describeModel(string $model): ?ModelInfo
    {
        $model = trim($model);
        if ($model === '') {
            return null;
        }

        $response = $this->probe('/models/' . rawurlencode($model));

        if ($response !== null && $this->statusOf($response) === 200) {
            try {
                $data = $response->toArray(throw: false);
            } catch (\Throwable) {
                $data = [];
            }

            if (is_string($data['id'] ?? null)) {
                return $this->describeEntry($data);
            }
        }

        // Servers that do not serve one model at a time — several do not — are
        // asked for the list instead. A name absent from it is reported as
        // unknown, which is a fact the caller may override.
        $listing = $this->listing();

        // No list at all is not evidence against the name: there is nothing to
        // check it against, and the first request will say if it is wrong.
        if ($listing === null) {
            return new ModelInfo(name: $model);
        }

        foreach ($listing as $info) {
            if ($info->name === $model) {
                return $info;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $entry */
    private function describeEntry(array $entry): ModelInfo
    {
        if ($this->isAnthropic()) {
            return AnthropicWire::model($entry);
        }

        $window = $entry['context_length'] ?? $entry['max_model_len'] ?? null;

        return new ModelInfo(
            name: (string) $entry['id'],
            contextLength: is_numeric($window) ? (int) $window : null,
        );
    }

    /**
     * A metadata request, or null when there is nowhere to send one: no
     * address, or one the client cannot parse. Both mean "not available".
     */
    private function probe(string $path): ?\Symfony\Contracts\HttpClient\ResponseInterface
    {
        if ($this->baseUrl === '') {
            return null;
        }

        $headers = $this->headers('application/json');
        unset($headers['Content-Type']);

        try {
            return $this->client->request('GET', $this->baseUrl . $path, [
                'headers' => $headers,
                'timeout' => self::PROBE_TIMEOUT,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function useModel(string $model, int $contextWindow): void
    {
        $model = trim($model);

        if ($model === '' || $contextWindow <= 0) {
            return;
        }

        $this->model = $model;
        $this->contextWindow = $contextWindow;

        // What one model refused says nothing about the next one, and the
        // rejections this learns from are per model, not per provider.
        $this->sendsTemperature = true;
        $this->sendsStreamOptions = true;
        $this->maxTokensField = 'max_tokens';
    }
}
