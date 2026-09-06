<?php

namespace App\Platform;

use App\Runtime\Interrupt;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OllamaPlatform implements PlatformInterface, EmbeddingBackend
{
    /**
     * Token counts from the server's own tokenizer: the only accurate figure a
     * PHP client can get, and what ContextBudget calibrates against.
     *
     * @var array{prompt: int, completion: int}|null
     */
    private ?array $lastUsage = null;

    /**
     * Throughput of the last request, in tokens per second, from the durations
     * Ollama closes every reply with. Without them a dozen constants elsewhere
     * were guessing whether this machine had a graphics card.
     *
     * @var array{prefill: float, decode: float}|null
     */
    private ?array $lastTimings = null;

    /**
     * Liveness probes, not inference. These answer instantly or not at all, so
     * they keep a short leash — hanging on them is worse than failing.
     */
    private const PROBE_TIMEOUT = 5;

    /**
     * /api/show reads the manifest and returns the licence with it, so it is
     * measurably heavier than /api/tags — but it is still metadata, not
     * inference, and it runs while someone waits on a menu.
     */
    private const SHOW_TIMEOUT = 20;

    /**
     * How often to surface control while the server is silent. Nothing arrives
     * during the prompt-evaluation pass — the longest part of the wait on a
     * CPU, and exactly when someone reaches for Ctrl+C.
     */
    private const POLL_SECONDS = 0.5;

    /**
     * @param float $timeout Idle timeout in seconds for inference requests.
     *                       Negative means unlimited.
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $baseUrl,
        // Not readonly: the model and the window it is asked for are chosen at
        // startup and can be changed mid-session with /model. They travel
        // together — see useModel(), which is the only way to move either.
        private string $model,
        private int $numCtx = 32768,
        private readonly float $timeout = -1.0,
        // Optional only because it follows defaulted parameters; services.yaml
        // wires it explicitly and wiring_test asserts it arrived.
        private readonly ?Interrupt $interrupt = null,
        // Last on purpose: several tests pass $interrupt positionally.
        //
        // How long Ollama keeps the model resident. Its own default is five
        // minutes, shorter than reading a diff — which on a graphics card means
        // re-uploading tens of gigabytes because someone paused to think.
        private readonly string $keepAlive = '30m',
        /**
         * Sampling temperature. Ollama's own default is roughly 0.8, a
         * creative-writing figure on a system whose output is all structured.
         * Not zero either: greedy decoding walks some models into repetition.
         */
        private readonly float $temperature = 0.15,
        /**
         * Hard ceiling on one reply, which is what makes ContextBudget's
         * reservation true; both come from one parameter in services.yaml.
         */
        private readonly int $maxTokens = 4096,
    ) {}

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'num_ctx'     => $this->numCtx,
            'temperature' => $this->temperature,
            'num_predict' => $this->maxTokens,
        ];
    }

    /**
     * The history, in the one respect where PHP and Ollama's decoder disagree:
     * a call with no arguments holds [], which json_encode writes as a list
     * where the decoder wants an object. Only that field is touched.
     *
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private function wireMessages(array $messages): array
    {
        foreach ($messages as $i => $message) {
            foreach ($message['tool_calls'] ?? [] as $j => $call) {
                if (($call['function']['arguments'] ?? null) === []) {
                    $messages[$i]['tool_calls'][$j]['function']['arguments'] = new \stdClass();
                }
            }
        }

        return $messages;
    }

    /**
     * Streaming chat — calls $onToken(string $delta) for each text token.
     * Returns the final complete message (for tool_calls detection).
     */
    public function stream(array $messages, array $tools = [], ?callable $onToken = null, ?callable $onWait = null): array
    {
        $payload = [
            'model'      => $this->model,
            'messages'   => $this->wireMessages($messages),
            'stream'     => true,
            'keep_alive' => $this->keepAlive,
            'options'    => $this->options(),
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        // Streaming does not make the wait shorter, only visible sooner: the
        // server is silent for the whole prompt-evaluation pass, minutes on a
        // CPU. That silence is what an idle timeout measures, hence off by default.
        $response = $this->client->request('POST', $this->baseUrl . '/api/chat', [
            'json'    => $payload,
            'timeout' => $this->timeout,
        ]);

        $fullContent = '';
        $toolCalls = [];
        $buffer = '';
        $this->lastUsage = null;
        $this->lastTimings = null;

        $cancelled = false;
        $silence = true;

        // Symfony removes a response from the stream generator the moment that
        // generator yields an idle-timeout chunk, so the loop below ends after
        // every quiet poll interval — not when the reply does. Re-entering it
        // is how streaming continues. Without this outer loop the first half
        // second of silence truncates the answer and reports nothing wrong.
        while ($silence) {
            $silence = false;

            foreach ($this->client->stream($response, self::POLL_SECONDS) as $chunk) {
                // Must come first on every chunk: it is also what marks a
                // timeout chunk as handled, and an unhandled one throws when it
                // is destroyed. On a genuine transport error it throws here.
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
                $buffer = array_pop($lines); // last incomplete line stays in buffer

                foreach ($lines as $line) {
                    $this->consumeLine($line, $fullContent, $toolCalls, $onToken, $onWait);
                }
            }

            if ($this->interrupt?->requested()) {
                // Drop the connection rather than let the server keep spending
                // on a reply nobody is going to read — seconds of a GPU or
                // minutes of a CPU, both wasted.
                $response->cancel();
                $cancelled = true;
                $silence = false;
            }
        }

        // The final NDJSON line may arrive without a trailing newline. Skipped
        // when cancelled: the tail is a fragment, not a message.
        if (!$cancelled) {
            $this->consumeLine($buffer, $fullContent, $toolCalls, $onToken);
        }

        $message = ['role' => 'assistant', 'content' => $fullContent];
        if ($toolCalls !== []) {
            $message['tool_calls'] = array_values($toolCalls);
        }

        return $message;
    }

    /**
     * Parse one NDJSON line of the stream, folding it into the accumulated
     * content and tool calls.
     */
    private function consumeLine(string $line, string &$content, array &$toolCalls, ?callable $onToken, ?callable $onWait = null): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        $data = json_decode($line, true);
        if (!is_array($data)) {
            return;
        }

        // Only the final chunk carries the counts, and the durations beside
        // them. Nanoseconds, both of them.
        if (isset($data['prompt_eval_count']) || isset($data['eval_count'])) {
            $this->lastUsage = [
                'prompt'     => (int) ($data['prompt_eval_count'] ?? 0),
                'completion' => (int) ($data['eval_count'] ?? 0),
            ];

            $this->lastTimings = $this->timingsFrom($data, $this->lastUsage);
        }

        $msg = $data['message'] ?? [];

        // Thinking models stream their reasoning in a field of its own. It is
        // not shown, but it is not silence either.
        if ($onWait !== null && is_string($msg['thinking'] ?? null) && $msg['thinking'] !== '') {
            $onWait(true);
        }

        if (!empty($msg['tool_calls'])) {
            $this->accumulateToolCalls($toolCalls, $msg['tool_calls']);
        }

        $delta = $msg['content'] ?? '';
        if ($delta !== '') {
            $content .= $delta;
            if ($onToken !== null) {
                $onToken($delta);
            }
        }
    }

    /**
     * Merge streamed tool-call fragments. Ollama emits each call complete;
     * OpenAI-style backends stream arguments as text to concatenate per index.
     * Overwriting rather than accumulating drops every call but the last.
     */
    private function accumulateToolCalls(array &$acc, array $deltas): void
    {
        foreach ($deltas as $position => $delta) {
            $function = $delta['function'] ?? [];
            $arguments = $function['arguments'] ?? null;

            if (is_array($arguments)) {
                $acc[] = $delta;
                continue;
            }

            $key = 'partial:' . ($delta['index'] ?? $position);

            if (!isset($acc[$key])) {
                $acc[$key] = ['function' => ['name' => '', 'arguments' => '']];
            }
            if (isset($delta['id'])) {
                $acc[$key]['id'] = $delta['id'];
            }
            if (!empty($function['name'])) {
                $acc[$key]['function']['name'] = $function['name'];
            }
            if (is_string($arguments)) {
                $acc[$key]['function']['arguments'] .= $arguments;
            }
        }
    }

    /**
     * Tokens per second for each phase, when both are reportable. A zero
     * duration is a phase that did not happen — a prompt served from the cache,
     * a reply that is only a tool call — and InferenceSpeed discards it.
     *
     * @param array<string, mixed>                $data
     * @param array{prompt: int, completion: int} $usage
     *
     * @return array{prefill: float, decode: float}|null
     */
    private function timingsFrom(array $data, array $usage): ?array
    {
        $promptNs = is_numeric($data['prompt_eval_duration'] ?? null) ? (int) $data['prompt_eval_duration'] : 0;
        $evalNs   = is_numeric($data['eval_duration'] ?? null) ? (int) $data['eval_duration'] : 0;

        if ($promptNs <= 0 && $evalNs <= 0) {
            return null;
        }

        // Cast, not decoration: PHP's / yields an int when both operands are
        // ints and the division is exact, so a tidy 2s pass over 1000 tokens
        // returns int(500) from a method that declares float — and every ===
        // against it, in a test or a caller, quietly fails.
        return [
            'prefill' => $promptNs > 0 ? (float) ($usage['prompt'] / ($promptNs / 1_000_000_000)) : 0.0,
            'decode'  => $evalNs > 0 ? (float) ($usage['completion'] / ($evalNs / 1_000_000_000)) : 0.0,
        ];
    }

    /**
     * @return array{prefill: float, decode: float}|null
     */
    public function lastTimings(): ?array
    {
        return $this->lastTimings;
    }

    /**
     * What /api/ps says about the model right now. Null covers three states the
     * caller treats alike: no server, no such route, or the model not loaded —
     * which is normal until the first reply, since Ollama loads lazily.
     */
    public function residency(): ?ModelResidency
    {
        try {
            $response = $this->client->request('GET', $this->baseUrl . '/api/ps', ['timeout' => self::PROBE_TIMEOUT]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = $response->toArray(throw: false);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        foreach ($data['models'] ?? [] as $entry) {
            if (!is_array($entry) || !$this->namesCurrentModel($entry)) {
                continue;
            }

            $size = $entry['size'] ?? null;
            if (!is_numeric($size)) {
                continue;
            }

            $vram = $entry['size_vram'] ?? null;

            return new ModelResidency(
                totalBytes: (int) $size,
                vramBytes: is_numeric($vram) ? (int) $vram : 0,
            );
        }

        return null;
    }

    /**
     * Whether a /api/ps entry describes the model in force. Ollama answers with
     * the fully-qualified name, so "qwen3-coder" comes back as
     * "qwen3-coder:latest" and strict matching would report no GPU at all.
     *
     * @param array<string, mixed> $entry
     */
    private function namesCurrentModel(array $entry): bool
    {
        $wanted = strtolower(trim($this->model));
        if ($wanted === '') {
            return false;
        }

        $bare = strstr($wanted, ':', true) ?: $wanted;

        foreach ([$entry['name'] ?? null, $entry['model'] ?? null] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = strtolower(trim($candidate));

            if ($candidate === $wanted || $candidate === $bare . ':latest') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{prompt: int, completion: int}|null
     */
    public function lastUsage(): ?array
    {
        return $this->lastUsage;
    }

    public function contextWindow(): int
    {
        return $this->numCtx;
    }

    public function modelName(): string
    {
        return $this->model;
    }

    /**
     * /api/embed, with an embedding model pulled like any other (bge-m3,
     * nomic-embed-text). It loads beside the chat model, so on a small card
     * the first search after a chat turn waits for a swap.
     */
    public function embed(array $texts, string $model): array
    {
        $vectors = [];

        foreach (array_chunk($texts, 32) as $batch) {
            try {
                $response = $this->client->request('POST', $this->baseUrl . '/api/embed', [
                    'json'    => ['model' => $model, 'input' => $batch],
                    'timeout' => 120,
                ]);
                $status = $response->getStatusCode();
                $body = $response->getContent(throw: false);
            } catch (\Throwable $e) {
                throw new \RuntimeException("Embeddings: Ollama unreachable ({$e->getMessage()}).", previous: $e);
            }

            if ($status === 404) {
                throw new UnknownEmbeddingModel("Embeddings: Ollama does not have \"{$model}\". Pull it with: ollama pull {$model}");
            }

            $rows = json_decode($body, true)['embeddings'] ?? null;
            if ($status >= 400 || !is_array($rows) || count($rows) !== count($batch)) {
                throw new \RuntimeException("Embeddings: unexpected answer from Ollama for \"{$model}\" (HTTP {$status}).");
            }

            foreach ($rows as $row) {
                $vectors[] = array_map('floatval', (array) $row);
            }
        }

        return $vectors;
    }

    public function name(): string
    {
        return 'Ollama';
    }

    /** Where this platform expects Ollama to answer, for saying where it did not. */
    public function endpoint(): string
    {
        return $this->baseUrl;
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->client->request('GET', $this->baseUrl . '/api/tags', ['timeout' => self::PROBE_TIMEOUT]);
            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every model this server holds. /api/tags carries the window and the
     * capabilities beside the name on current versions, so the selection screen
     * costs one request; an older server omits them and describeModel() probes.
     *
     * @return array<int, ModelInfo>
     */
    public function catalogue(): array
    {
        try {
            $response = $this->client->request('GET', $this->baseUrl . '/api/tags', ['timeout' => self::PROBE_TIMEOUT]);
            $data = $response->toArray();
        } catch (\Throwable) {
            return [];
        }

        $models = [];

        foreach ($data['models'] ?? [] as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
                continue;
            }

            $details = is_array($entry['details'] ?? null) ? $entry['details'] : [];
            $context = $details['context_length'] ?? null;

            $models[] = new ModelInfo(
                name: $entry['name'],
                contextLength: is_numeric($context) ? (int) $context : null,
                capabilities: array_values(array_filter(
                    is_array($entry['capabilities'] ?? null) ? $entry['capabilities'] : [],
                    is_string(...),
                )),
                sizeBytes: is_numeric($entry['size'] ?? null) ? (int) $entry['size'] : null,
                parameterSize: is_string($details['parameter_size'] ?? null) ? $details['parameter_size'] : null,
            );
        }

        // Biggest first: on a machine with a GPU worth having, that is the one
        // being looked for, and it is also the one whose window needs thought.
        usort($models, fn(ModelInfo $a, ModelInfo $b) => ($b->sizeBytes ?? 0) <=> ($a->sizeBytes ?? 0));

        return $models;
    }

    /**
     * One model, named rather than picked off a list — which is what makes a
     * model Sherpa has never seen a valid answer. A model the server does not
     * hold returns null ("run ollama pull"); a broken server throws.
     */
    public function describeModel(string $model): ?ModelInfo
    {
        $model = trim($model);
        if ($model === '') {
            return null;
        }

        try {
            $response = $this->client->request('POST', $this->baseUrl . '/api/show', [
                'json'    => ['model' => $model],
                'timeout' => self::SHOW_TIMEOUT,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = $response->toArray(throw: false);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($data) || isset($data['error'])) {
            return null;
        }

        $details = is_array($data['details'] ?? null) ? $data['details'] : [];

        return new ModelInfo(
            name: $model,
            contextLength: $this->contextLengthOf($data, $details),
            capabilities: array_values(array_filter(
                is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [],
                is_string(...),
            )),
            sizeBytes: is_numeric($data['size'] ?? null) ? (int) $data['size'] : null,
            parameterSize: is_string($details['parameter_size'] ?? null) ? $details['parameter_size'] : null,
        );
    }

    /**
     * The native window, wherever this build put it. model_info keys are
     * namespaced by architecture (qwen2, qwen3moe, mistral3…), and the
     * rope-scaling key ends in the same two words while meaning the length
     * *before* extension — which is how a 393k model reads as an 8k one.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $details
     */
    private function contextLengthOf(array $data, array $details): ?int
    {
        if (is_numeric($details['context_length'] ?? null)) {
            return (int) $details['context_length'];
        }

        $info = is_array($data['model_info'] ?? null) ? $data['model_info'] : [];

        foreach ($info as $key => $value) {
            if (!is_string($key) || !is_numeric($value)) {
                continue;
            }
            if (str_contains($key, 'rope')) {
                continue;
            }
            if (str_ends_with($key, '.context_length')) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Point this platform at another model. The window moves with it: a 32k
     * model asked for its predecessor's 256k window is reinterpreted silently.
     */
    public function useModel(string $model, int $contextWindow): void
    {
        $model = trim($model);

        if ($model !== '') {
            $this->model = $model;
        }
        if ($contextWindow > 0) {
            $this->numCtx = $contextWindow;
        }

        // Counts from the previous model describe the previous tokenizer, and
        // its throughput describes how the previous weights were loaded.
        $this->lastUsage = null;
        $this->lastTimings = null;
    }
}
