<?php

namespace App\Platform;

use App\Runtime\Interrupt;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OllamaPlatform implements PlatformInterface
{
    /**
     * Token counts reported by Ollama for the most recent request. These are
     * ground truth from the server's own tokenizer — the only accurate figure
     * available to a PHP client, and what ContextBudget calibrates against.
     *
     * @var array{prompt: int, completion: int}|null
     */
    private ?array $lastUsage = null;

    /**
     * Throughput of the most recent request, in tokens per second.
     *
     * Ollama closes every reply with `prompt_eval_duration` and `eval_duration`
     * beside the counts above. They were being parsed past and discarded, which
     * left Sherpa with no idea whether it was running on a graphics card or on
     * a laptop — and a dozen constants elsewhere guessing on its behalf.
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
     * How often to surface control while the server is silent.
     *
     * The stream only yields when bytes arrive, and nothing arrives during the
     * prompt-evaluation pass — a blink on a GPU, the longest part of the wait on
     * a CPU, and in both cases exactly when someone reaches for Ctrl+C. Asking
     * the client for timeout chunks at this interval gives cancellation
     * somewhere to be noticed. Sized for the slow end; free at the fast one.
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
        // Last on purpose: several tests pass $interrupt positionally, and a
        // new parameter ahead of it would silently become the interrupt.
        //
        // How long Ollama keeps the model resident after a reply. Its own
        // default is five minutes, which is shorter than reading a diff — on a
        // machine with a graphics card that means re-uploading tens of
        // gigabytes of weights because someone thought about their next
        // message. Nothing noticed while a turn itself took minutes.
        private readonly string $keepAlive = '30m',
    ) {}

    /**
     * Streaming chat — calls $onToken(string $delta) for each text token.
     * Returns the final complete message (for tool_calls detection).
     */
    public function stream(array $messages, array $tools = [], ?callable $onToken = null): array
    {
        $payload = [
            'model'      => $this->model,
            'messages'   => $messages,
            'stream'     => true,
            'keep_alive' => $this->keepAlive,
            'options'    => ['num_ctx' => $this->numCtx],
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        // Streaming does not make the wait shorter, only visible sooner: the
        // server is silent for the whole prompt-evaluation pass, which is a
        // moment on a GPU and minutes for a full window on a CPU. That silence
        // is exactly what an idle timeout measures, which is why the default
        // one is off — see the note in chat().
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
                    break;
                }

                if ($this->interrupt?->requested()) {
                    break;
                }

                $buffer .= $chunk->getContent();
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines); // last incomplete line stays in buffer

                foreach ($lines as $line) {
                    $this->consumeLine($line, $fullContent, $toolCalls, $onToken);
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
    private function consumeLine(string $line, string &$content, array &$toolCalls, ?callable $onToken): void
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
     * Merge streamed tool-call fragments across chunks.
     *
     * Ollama emits each tool call complete, with `arguments` as an object — those
     * are appended as-is. OpenAI-style backends instead stream `arguments` as
     * string fragments that must be concatenated per index; those are merged.
     * Overwriting here (rather than accumulating) silently drops every tool call
     * but the last.
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
     * Tokens per second for each of the two phases, when both are reportable.
     *
     * A zero duration is not a division to guard against so much as a phase
     * that did not happen: a request served entirely from the prefix cache
     * evaluates no prompt, and a reply that is nothing but a tool call decodes
     * almost nothing. Either one yields 0.0, which InferenceSpeed discards
     * rather than folds in.
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
     * What /api/ps says about the model right now.
     *
     * Null covers three states that the caller treats alike: the server could
     * not be reached, it does not answer this route, or it is not holding this
     * model at the moment. Ollama loads lazily, so "not yet" is the normal
     * answer until the first reply has come back.
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
     * Whether a /api/ps entry describes the model in force.
     *
     * Ollama answers with the fully-qualified name, so a session started on
     * "qwen3-coder" is looking at an entry called "qwen3-coder:latest". Matching
     * strictly would report every such session as having no GPU at all.
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

    public function name(): string
    {
        return 'Ollama';
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
     * Every model this server already holds.
     *
     * /api/tags carries the context length and the capabilities alongside the
     * name on current Ollama versions, so the whole selection screen costs one
     * request. Where an older server omits them the fields come back null and
     * the caller probes only the model actually chosen — see describeModel().
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
     * One model, named rather than picked off a list.
     *
     * This is what makes a model Sherpa has never seen a valid answer: the name
     * is asked about directly. A model the server does not hold answers 404,
     * which is a fact worth reporting ("run ollama pull") rather than an error
     * to swallow — so absence returns null and a broken server throws.
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
     * The native window, wherever this build of Ollama chose to put it.
     *
     * model_info keys are namespaced by architecture — qwen2.context_length,
     * qwen3moe.context_length, mistral3.context_length — so the family has to
     * be discovered rather than assumed. The rope-scaling key ends in the same
     * two words and means the opposite thing (the length *before* extension),
     * which is how a 393k model gets read as an 8k one.
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
     * Point this platform at another model.
     *
     * The window moves with it and is never left behind: a 32k model asked for
     * the 256k window of the one it replaced is a request the server has to
     * reinterpret, and reinterpretation here is silent.
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
