<?php

declare(strict_types=1);

namespace App\Command;

use App\Agent\ContextBudget;
use App\Agent\InferenceSpeed;
use App\Agent\MessageBag;
use App\Agent\SystemPromptBuilder;
use App\Agent\Tool\Toolbox;
use App\Config\ApiEndpoint;
use App\Config\Backend;
use App\Config\ModelProfile;
use App\Config\ModelResolver;
use App\Config\ProviderName;
use App\Memory\ContextStore;
use App\Session\SessionStore;
use App\Platform\SwitchablePlatform;
use App\Project\Project;
use App\Rag\Embedding\EmbeddingDetector;
use App\TUI\ChatPane;
use App\TUI\LineEditor;
use App\TUI\ModelSelector;
use App\TUI\Terminal;

/**
 * The model this session runs on, and every way it changes: settled at boot
 * from the precedence chain, asked for when this machine never chose, and
 * changed from /model, /backend or /provider. Apart from the command because a model
 * change is not one assignment but five — platform, budget, speed, price and,
 * for a genuinely different model, the conversation itself.
 */
final class ModelSession
{
    /** The model in force. Set at boot, replaced by /model and /backend. */
    private ?ModelProfile $profile = null;

    public function __construct(
        private readonly SwitchablePlatform $platform,
        private readonly ModelResolver $models,
        private readonly ModelSelector $modelSelector,
        private readonly ContextBudget $budget,
        private readonly InferenceSpeed $speed,
        private readonly \App\Usage\UsageMeter $usage,
        private readonly ChatPane $chat,
        private readonly LineEditor $lineEditor,
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly ContextStore $contextStore,
        private readonly Toolbox $toolbox,
        private readonly SessionStore $sessions,
    ) {}

    public function profile(): ?ModelProfile
    {
        return $this->profile;
    }

    /**
     * Walk the precedence chain, and ask when it runs out.
     *
     * @return ModelProfile|null null only when someone chose to leave rather
     *                           than answer
     */
    public function resolve(?string $model, ?int $context, Backend $backend, bool $rememberAsDefault): ?ModelProfile
    {
        $explicit = $this->models->fromCommandLine($model, $context, $backend);

        if ($explicit !== null) {
            return $this->verified($explicit);
        }

        $stored = $this->models->stored($backend);
        if ($stored !== null) {
            return $this->verified($stored);
        }

        // Nothing chosen on this machine yet. A menu is useless to a pipe, so a
        // scripted run takes the configured default and is told which one.
        if (!$this->lineEditor->isInteractive()) {
            $fallback = $this->models->fallback($backend);

            if ($fallback->model !== '') {
                echo Terminal::YELLOW . "⚠ No model chosen on this machine; using \"{$fallback->model}\".\n"
                    . Terminal::GRAY . "  Run sherpa in a terminal to choose, or write "
                    . $this->models->configPath() . ".\n" . Terminal::RESET;
            }

            return $fallback;
        }

        $chosen = $this->modelSelector->run(firstRun: true, backend: $backend);
        if ($chosen === null) {
            return null;
        }

        // Said either way: a choice believed permanent and not would only be
        // discovered at the next launch, by being asked again.
        if ($this->models->remember($chosen, $rememberAsDefault)) {
            echo Terminal::GREEN . '✓ Choice saved in ' . Terminal::RESET
                . Terminal::GRAY . $this->models->configPath() . "\n" . Terminal::RESET;
        } else {
            echo Terminal::YELLOW . '⚠ Cannot write ' . $this->models->configPath()
                . " — the choice holds for this session only.\n" . Terminal::RESET;
        }

        return $chosen;
    }
    /**
     * Check a profile nobody looked at on the way in — from -m or a hand-edited
     * config.yaml. Warnings, not refusals: an unexpected answer is more likely
     * a model about to be pulled. Only a window past the ceiling is corrected
     * outright, since the backend would reinterpret it silently.
     */
    public function verified(ModelProfile $profile): ModelProfile
    {
        $info = $this->platform->describeModel($profile->model);

        if ($info === null) {
            echo Terminal::YELLOW . "⚠ " . $this->platform->name() . " does not know \"{$profile->model}\".\n" . Terminal::RESET;
            echo Terminal::GRAY . '  ' . $this->missingModelHint($profile->model, $profile->backend) . "\n"
                . "  Or change it with /model once the session is open.\n" . Terminal::RESET;

            return $profile;
        }

        if (!$info->supportsTools()) {
            echo Terminal::RED . "⚠ {$profile->model} cannot call tools.\n" . Terminal::RESET;
            echo Terminal::GRAY . "  With it, Sherpa can neither read a file nor run a command.\n"
                . "  /model to choose another one.\n" . Terminal::RESET;

            return $profile;
        }

        if ($info->contextLength !== null && $profile->contextWindow > $info->contextLength) {
            echo Terminal::YELLOW . '⚠ Window asked for (' . number_format($profile->contextWindow, 0, '.', ',')
                . ') is past what ' . $profile->model . ' takes; brought down to '
                . number_format($info->contextLength, 0, '.', ',') . ".\n" . Terminal::RESET;

            return $profile->withContext($info->contextLength);
        }

        return $profile;
    }
    /**
     * Bind a profile to the services holding a copy of it — always together: a
     * window from one model beside a name from another is undetectable.
     */
    public function apply(ModelProfile $profile): void
    {
        $this->profile = $profile;
        $this->platform->switchTo($profile->backend);
        $this->platform->useModel($profile->model, $profile->contextWindow);

        // Keyed by backend as well as by name: the same string can be a local
        // tag and a hosted id, with a different tokenizer and a speed two
        // orders of magnitude apart.
        // And by provider: the same id served by two of them is two speeds.
        $identity = $profile->backend->value . ':'
            . ($profile->backend->isLocal() ? '' : $this->platform->name() . ':')
            . $profile->model;
        $this->budget->useModel($identity, $profile->contextWindow);
        // Same reason as the window: a throughput learned from another model
        // describes something that is no longer running.
        $this->speed->useModel($identity);

        // Requests from here on are billed at this model's price; the ones
        // already made keep the price they were made at.
        $this->usage->usePrice($this->models->price($profile));
    }
    /**
     * The model in force and, when it is not on this machine, where the code it
     * reads goes. Sending it is the user's call; knowing it is, is not.
     */
    public function announce(ModelProfile $profile): void
    {
        echo Terminal::GREEN . '✓ Model: ' . Terminal::BOLD . $profile->model . Terminal::RESET
            . Terminal::GRAY . ' (' . $this->whereItRuns($profile->backend) . ' · '
            . number_format($profile->contextWindow, 0, '.', ',') . ' tokens · '
            . $profile->source . ")\n" . Terminal::RESET;

        if (!$profile->backend->isLocal()) {
            echo Terminal::GRAY . '  The files and command output Sherpa reads are sent to '
                . $this->platform->name() . ".\n" . Terminal::RESET;
        }
    }
    /**
     * Settle the embedding model for $backend, once: nobody is asked, since the
     * user gives an address, a key and a chat model, and a chat model cannot
     * make vectors. Skipped in a pipe, where a script sets what it wants.
     */
    public function settleEmbeddings(Backend $backend): void
    {
        if ($this->lineEditor->isInteractive()) {
            $this->detectEmbeddings($backend);
        }
    }

    /** The detection itself; public for the tests, which have no terminal. */
    public function detectEmbeddings(Backend $backend): void
    {
        if ($this->models->embeddingChosen($backend)) {
            return;
        }

        $found = (new EmbeddingDetector($this->platform))->detect($backend);

        if (!$found->settled) {
            echo Terminal::GRAY . '  Documentation search: by keywords for now — could not check for an embedding model ('
                . Terminal::plain($found->reason, singleLine: true) . "). Sherpa looks again next launch.\n" . Terminal::RESET;

            return;
        }

        $this->models->rememberEmbeddingModel($backend, $found->model ?? '');

        echo $found->model !== null
            ? Terminal::GREEN . '✓ Documentation search: by meaning, with ' . $found->model . "\n" . Terminal::RESET
            : Terminal::GRAY . "  Documentation search: by keywords — {$found->reason}. "
                . 'Set embedding_model in ' . $this->models->configPath() . " to use one.\n" . Terminal::RESET;
    }

    public function whereItRuns(Backend $backend): string
    {
        return $backend->isLocal()
            ? 'local, Ollama'
            : 'online, ' . $this->platform->name();
    }
    /** What to do about a model the backend does not know, in that backend's terms. */
    private function missingModelHint(string $model, Backend $backend): string
    {
        return $backend->isLocal()
            ? "Pull it:  ollama pull {$model}"
            : 'Check the exact id with the provider: /model lists them.';
    }
    /**
     * Make sure the API has an address: the environment's, this machine's, or
     * one asked for now.
     *
     * @return bool false when the person chose to run locally instead
     */
    public function configureApiEndpoint(): bool
    {
        $known = $this->models->apiUrl($this->platform->apiEndpoint());
        if ($known !== null) {
            $this->platform->useApiEndpoint($known);
            $this->platform->useApiKey($this->models->apiKey($known));

            return true;
        }

        // A pipe cannot answer; explainUnavailable() says what to set.
        if (!$this->lineEditor->isInteractive()) {
            return true;
        }

        echo Terminal::BOLD . Terminal::CYAN . "\n  Sherpa · Which provider?\n" . Terminal::RESET;
        echo Terminal::GRAY
            . "  Sherpa talks to an online model through an OpenAI-compatible API.\n"
            . "  Its address, up to the version segment — for example:\n"
            . "    https://api.openai.com/v1          https://api.mistral.ai/v1\n"
            . "    https://openrouter.ai/api/v1       http://localhost:8000/v1  (vLLM, LM Studio…)\n"
            . "  Empty line to use a local model (Ollama) instead.\n\n" . Terminal::RESET;

        // Checked before it is written down: a wrong address is never asked
        // about again. Kept anyway on request — usually a key typed wrong.
        $typedKey = '';
        while (true) {
            $url = $this->askApiEndpoint();
            if ($url === null) {
                echo Terminal::GRAY . "  Local model (Ollama) this time. /backend api, or sherpa -b api, to go back.\n"
                    . Terminal::RESET;

                return false;
            }

            $this->platform->useApiEndpoint($url);
            $this->platform->useApiKey(null);
            $typedKey = '';
            if (!$this->platform->apiHasKey()) {
                $typedKey = $this->lineEditor->askSecret('  API key (hidden; Enter if it needs none): ', Terminal::BOLD);
                $this->platform->useApiKey($typedKey !== '' ? $typedKey : null);
            }
            if ($this->platform->isAvailable()) {
                break;
            }

            // The explanation on its own line: a prompt wider than the
            // terminal is scrolled sideways and reads as "<  <".
            echo Terminal::YELLOW . "  {$url} does not answer, or refuses the key.\n" . Terminal::RESET;
            $keep = strtolower(trim($this->lineEditor->ask('  Keep it anyway? [y/N]: ', Terminal::BOLD)));
            if (in_array($keep, ['o', 'oui', 'y', 'yes'], true)) {
                break;
            }
        }

        echo $this->models->rememberApiUrl($url)
            ? Terminal::GREEN . '✓ Address saved in ' . Terminal::RESET . Terminal::GRAY . $this->models->configPath() . "\n" . Terminal::RESET
            : Terminal::YELLOW . '⚠ Cannot write ' . $this->models->configPath() . " — the address holds for this session only.\n" . Terminal::RESET;

        $provider = $this->models->activeProvider();
        if ($typedKey !== '' && $provider !== null) {
            echo $this->models->saveKey($provider, $typedKey)
                ? Terminal::GREEN . '✓ Key saved in ' . Terminal::RESET . Terminal::GRAY . $this->models->keysPath() . " (readable by you only)\n" . Terminal::RESET
                : Terminal::YELLOW . '⚠ Cannot write ' . $this->models->keysPath() . " — the key holds for this session only.\n" . Terminal::RESET;
        }

        return true;
    }
    /** One typed address, asked until it is one; null when the line is left empty. */
    private function askApiEndpoint(string $emptyMeans = 'Enter to run locally'): ?string
    {
        while (true) {
            $typed = trim($this->lineEditor->ask('  API address: ', Terminal::BOLD));
            if ($typed === '') {
                return null;
            }

            $url = ApiEndpoint::normalize($typed);
            if ($url !== null) {
                return $url;
            }

            echo Terminal::YELLOW . "  \"{$typed}\" is not an http(s) address. Try again, or {$emptyMeans}.\n" . Terminal::RESET;
        }
    }
    /**
     * Why the backend in force cannot be reached, and the way out — including
     * the other backend, since either one is a complete way to run Sherpa.
     */
    public function explainUnavailable(Backend $backend): void
    {
        if ($backend->isLocal()) {
            echo Terminal::RED . '✗ Ollama does not answer (' . $this->platform->ollamaEndpoint() . ").\n" . Terminal::RESET;
            echo Terminal::GRAY . "  Start it (ollama serve), or check OLLAMA_URL in .env.\n"
                . "  Or use an online model:  sherpa -b api\n" . Terminal::RESET;

            return;
        }

        if ($this->platform->apiEndpoint() === '') {
            echo Terminal::RED . "✗ No API configured.\n" . Terminal::RESET;
            echo Terminal::GRAY
                . "  Run sherpa in a terminal: it asks for the address and keeps it.\n"
                . "  Or give it with its key, for example:\n"
                . "    export SHERPA_API_URL=https://api.openai.com/v1\n"
                . "    export SHERPA_API_KEY=…\n"
                . "  Or use a local model:  sherpa -b ollama\n" . Terminal::RESET;

            return;
        }

        echo Terminal::RED . '✗ The API does not answer (' . $this->platform->apiEndpoint() . ").\n" . Terminal::RESET;
        echo Terminal::GRAY
            . "  Check the address (SHERPA_API_URL, else api.url in " . $this->models->configPath() . ")\n"
            . "  and the key (SHERPA_API_KEY, exported in your shell or written in Sherpa's .env).\n"
            . "  Or use a local model:  sherpa -b ollama\n"
            . "  make doctor spells out what is missing.\n" . Terminal::RESET;
    }
    /**
     * Change models without leaving the session.
     *
     * `/model` opens the first-run screen; `/model <name>` takes a name
     * directly. Both go through the same two checks: a model that cannot call
     * tools is not a degraded session, it is one that can do nothing.
     */
    public function handleModel(string $args, MessageBag $bag, Project $project): void
    {
        if ($args === '') {
            $chosen = $this->modelSelector->run($this->profile, backend: $this->platform->backend());

            if ($chosen === null) {
                $this->chat->showInfo('Model unchanged: ' . ($this->profile?->model ?? $this->platform->modelName()));

                return;
            }

            $this->switchTo($chosen, $bag, $project);

            return;
        }

        $info = $this->platform->describeModel($args);

        if ($info === null) {
            echo Terminal::RED . $this->platform->name() . " does not know this model: {$args}\n" . Terminal::RESET;
            echo Terminal::GRAY . '  ' . $this->missingModelHint($args, $this->platform->backend()) . "\n"
                . "  Or /model with no argument to pick from the list.\n" . Terminal::RESET;

            return;
        }

        if (!$info->supportsTools()) {
            echo Terminal::RED . "{$info->name} cannot call tools.\n" . Terminal::RESET;
            echo Terminal::GRAY . "  With it, Sherpa could neither read a file nor run a command.\n" . Terminal::RESET;

            return;
        }

        // Keep the window already tuned for this machine where the model is
        // unchanged; otherwise start from what this one can actually take.
        $backend = $this->platform->backend();
        $profile = $this->profile !== null && $this->profile->backend === $backend && $this->profile->model === $info->name
            ? $this->profile
            : ModelProfile::suggestFor($info, 'chosen here', $backend);

        $this->switchTo($profile, $bag, $project);
    }
    /**
     * Apply a new profile mid-session. A different model starts from a clean
     * slate — the history holds tool calls shaped by the old template — and
     * retuning the window of the *same* model resets nothing.
     */
    private function switchTo(ModelProfile $profile, MessageBag $bag, Project $project): bool
    {
        $previous = $this->profile;
        $changed = !$profile->sameModelAs($previous);

        // Asked before anything moves, so declining leaves the session exactly
        // as it was rather than half-switched: the platform on the new model
        // and the conversation still belonging to the old one.
        if ($changed && !$this->confirmReset($bag, $profile)) {
            echo Terminal::GRAY . '  Model unchanged: '
                . ($previous?->model ?? $this->platform->modelName())
                . ". The conversation is untouched.\n" . Terminal::RESET;

            return false;
        }

        $this->apply($profile);
        $this->announce($profile->withSource('chosen here'));

        if ($changed) {
            $this->resetForNewModel($bag, $project);
        } elseif ($this->budget->needsCompaction($bag->all(), $this->toolbox->schemas())) {
            // Same model, smaller window: the history survives the change and
            // may no longer fit in what is left of it.
            $this->chat->showInfo(
                'The conversation is already past this window\'s compaction threshold: '
                . 'it will be compacted before the next request.'
            );
        }

        $answer = strtolower($this->lineEditor->ask(
            '  Keep this model for this machine? [y/N]: ',
            Terminal::BOLD,
        ));

        if (!in_array($answer, ['o', 'oui', 'y', 'yes'], true)) {
            echo Terminal::GRAY . "  This session only.\n" . Terminal::RESET;

            return true;
        }

        echo $this->models->remember($profile)
            ? Terminal::GREEN . '  ✓ Saved in ' . $this->models->configPath() . "\n" . Terminal::RESET
            : Terminal::YELLOW . '  ⚠ Cannot write ' . $this->models->configPath() . "\n" . Terminal::RESET;

        return true;
    }
    /**
     * Move between a model in the cloud and one on this machine, mid-session:
     * the same path as /model, one step earlier. Anything that fails on the way
     * leaves the session on the backend it was on — a half-switched one has its
     * conversation on one backend and its requests going to the other.
     */
    public function handleBackend(string $args, MessageBag $bag, Project $project): void
    {
        $current = $this->platform->backend();

        if ($args === '') {
            echo Terminal::BOLD . "\nBackend: " . Terminal::RESET . $this->whereItRuns($current) . "\n";
            echo Terminal::GRAY . '  /backend ' . ($current->isLocal() ? 'api' : 'ollama')
                . ' to move to the other one.' . "\n" . Terminal::RESET;

            return;
        }

        $target = Backend::parse($args);
        if ($target === null) {
            echo Terminal::RED . "No such backend: {$args}. Either api or ollama.\n" . Terminal::RESET;

            return;
        }

        if ($target === $current) {
            $this->chat->showInfo('Already on that backend: ' . $this->whereItRuns($current) . '.');

            return;
        }

        $this->platform->switchTo($target);

        $profile = null;
        if ($target === Backend::Api && !$this->configureApiEndpoint()) {
            // Declined the question: stay where we were.
        } elseif (!$this->platform->isAvailable()) {
            $this->explainUnavailable($target);
        } else {
            $stored = $this->models->stored($target);
            $profile = $stored !== null
                ? $this->verified($stored)
                : $this->modelSelector->run(backend: $target);
        }

        if ($profile === null || $profile->model === '' || !$this->switchTo($profile, $bag, $project)) {
            $this->platform->switchTo($current);
            echo Terminal::GRAY . '  Still on ' . $this->whereItRuns($current) . ".\n" . Terminal::RESET;

            return;
        }

        $this->settleEmbeddings($target);
    }
    /**
     * The API providers this machine knows, and moving between them.
     *
     *   /provider                 the list, the one in force marked
     *   /provider add             record one: address, name, key variable
     *   /provider <name>          switch to it
     *   /provider key [<name>]    change its key (the one in force by default)
     *   /provider remove <name>   forget it
     */
    public function handleProvider(string $args, MessageBag $bag, Project $project): void
    {
        [$verb, $rest] = array_pad(preg_split('/\s+/', $args, 2) ?: [], 2, '');

        match (strtolower($verb)) {
            ''       => $this->listProviders(),
            'add'    => $this->addProvider($bag, $project),
            'remove' => $this->removeProvider(trim($rest)),
            'key'    => $this->changeKey(trim($rest)),
            default  => $this->switchProvider($verb, $bag, $project),
        };
    }

    private function listProviders(): void
    {
        $providers = $this->models->providers();

        if ($providers === []) {
            $this->chat->showInfo('No API provider recorded yet. /provider add to record one.');

            return;
        }

        $active = $this->models->activeProvider();
        $onApi = !$this->platform->backend()->isLocal();
        $width = max(array_map('strlen', array_keys($providers)));

        echo Terminal::BOLD . "\nAPI providers:\n" . Terminal::RESET;
        foreach ($providers as $name => $provider) {
            $current = $name === $active;
            $variable = $provider['key_env'] ?? null;
            $inVariable = $variable !== null && trim((string) ($_SERVER[$variable] ?? $_ENV[$variable] ?? getenv($variable))) !== '';
            $key = match (true) {
                $inVariable                           => 'key: ' . $variable,
                $this->models->hasStoredKey($name)    => 'key: saved',
                $variable !== null                    => 'key: ' . $variable . ' (not set)',
                default                               => 'key: SHERPA_API_KEY' . ($this->envKeySet() ? '' : ' (not set)'),
            };

            echo ($current ? Terminal::GREEN . '  ● ' : '    ') . Terminal::BOLD . str_pad($name, $width) . Terminal::RESET
                . Terminal::GRAY . '  ' . ($provider['url'] ?? '(no address)')
                . '  ' . ($provider['model'] ?? '(no model yet)')
                . '  ' . $key . "\n" . Terminal::RESET;
        }

        $endpoint = $this->platform->apiEndpoint();
        if ($onApi && $active !== null && $endpoint !== ($providers[$active]['url'] ?? null)) {
            echo Terminal::YELLOW . '  In use now: ' . $endpoint . " (SHERPA_API_URL), not a recorded provider.\n" . Terminal::RESET;
        } elseif (!$onApi) {
            echo Terminal::GRAY . "  This session runs locally (Ollama): /backend api goes back to the one marked.\n" . Terminal::RESET;
        }

        echo Terminal::GRAY . "  /provider <name> to switch · /provider add · /provider key <name> · /provider remove <name>\n" . Terminal::RESET;
    }

    /**
     * Record a provider and move to it: address, name, key, checked on the
     * way, then the model list. The key is typed hidden and kept in keys.yaml,
     * readable by its owner only, so nothing has to be exported or restarted.
     */
    private function addProvider(MessageBag $bag, Project $project): void
    {
        echo Terminal::BOLD . Terminal::CYAN . "\n  Sherpa · New API provider\n" . Terminal::RESET;
        echo Terminal::GRAY
            . "  Its OpenAI-compatible address, up to the version segment — for example:\n"
            . "    https://api.openai.com/v1          https://api.groq.com/openai/v1\n"
            . "    https://generativelanguage.googleapis.com/v1beta/openai\n"
            . "  Empty line to cancel.\n\n" . Terminal::RESET;

        $url = $this->askApiEndpoint('Enter to cancel');
        if ($url === null) {
            $this->chat->showInfo('No provider added.');

            return;
        }

        $suggested = ProviderName::fromUrl($url);
        while (true) {
            $typed = trim($this->lineEditor->ask("  Name [{$suggested}]: ", Terminal::BOLD));
            $name = ProviderName::normalize($typed === '' ? $suggested : $typed);
            if ($name !== null) {
                break;
            }
            echo Terminal::YELLOW . "  A short word: letters, digits, - or _.\n" . Terminal::RESET;
        }

        if (isset($this->models->providers()[$name])) {
            $replace = strtolower(trim($this->lineEditor->ask("  \"{$name}\" exists already. Replace it? [y/N]: ", Terminal::BOLD)));
            if (!in_array($replace, ['o', 'oui', 'y', 'yes'], true)) {
                $this->chat->showInfo('No provider added.');

                return;
            }
        }

        $key = $this->askKey($url);
        if ($key === null) {
            $this->chat->showInfo('No provider added.');

            return;
        }

        if (!$this->models->addProvider($name, $url, null) || !$this->models->saveKey($name, $key)) {
            echo Terminal::YELLOW . '⚠ Cannot write ' . $this->models->configPath() . ' or ' . $this->models->keysPath() . "\n" . Terminal::RESET;

            return;
        }
        echo Terminal::GREEN . "✓ Provider {$name} saved" . Terminal::RESET
            . Terminal::GRAY . ($key !== '' ? ', its key in ' . $this->models->keysPath() . ' (readable by you only)' : '') . "\n" . Terminal::RESET;

        $this->switchProvider($name, $bag, $project);
    }

    /** /provider key [<name>]: a new key for a provider, checked, then in force at once. */
    private function changeKey(string $name): void
    {
        $name = $name === '' ? (string) $this->models->activeProvider() : (ProviderName::normalize($name) ?? $name);
        $url = $this->models->providers()[$name]['url'] ?? null;

        if ($url === null) {
            echo Terminal::RED . 'No such provider: ' . ($name === '' ? '(none recorded)' : $name) . "\n" . Terminal::RESET;
            echo Terminal::GRAY . "  /provider lists them.\n" . Terminal::RESET;

            return;
        }

        echo Terminal::BOLD . "\n  New key for {$name}" . Terminal::RESET . Terminal::GRAY . " ({$url})\n" . Terminal::RESET;
        $key = $this->askKey($url);
        if ($key === null) {
            $this->chat->showInfo("Key of {$name} unchanged.");

            return;
        }

        if (!$this->models->saveKey($name, $key)) {
            echo Terminal::YELLOW . '⚠ Cannot write ' . $this->models->keysPath() . "\n" . Terminal::RESET;

            return;
        }

        // In force at once when it is the provider this session talks to.
        if ($name === $this->models->activeProvider() && $this->platform->apiEndpoint() === $url) {
            $this->platform->useApiKey($this->models->apiKey($url));
        }

        echo Terminal::GREEN . "✓ Key of {$name} saved" . Terminal::RESET . Terminal::GRAY . ' in ' . $this->models->keysPath() . "\n" . Terminal::RESET;
    }

    /**
     * A key for $url, typed hidden and tried at once: '' for a server that
     * wants none, null when the person gives up.
     */
    private function askKey(string $url): ?string
    {
        while (true) {
            $key = $this->lineEditor->askSecret('  API key (hidden; Enter if it needs none): ', Terminal::BOLD);

            if ($this->answers($url, $key)) {
                echo Terminal::GREEN . "  ✓ {$url} answers" . ($key !== '' ? ' and accepts the key' : '') . ".\n" . Terminal::RESET;

                return $key;
            }

            echo Terminal::YELLOW . "  {$url} does not answer, or refuses " . ($key !== '' ? 'this key' : 'to go without one') . ".\n" . Terminal::RESET;
            $next = strtolower(trim($this->lineEditor->ask('  [r]etype it, [k]eep it anyway, [c]ancel: ', Terminal::BOLD)));
            if (in_array($next, ['k', 'keep', 'g', 'garder'], true)) {
                return $key;
            }
            if (!in_array($next, ['r', 'retype', 'retaper'], true)) {
                return null;
            }
        }
    }

    /** Whether $url answers with $key — tried, then the session put back as it was. */
    private function answers(string $url, string $key): bool
    {
        $backend = $this->platform->backend();
        $previousUrl = $this->platform->apiEndpoint();
        $previousKey = $this->platform->apiKeyOverride();

        $this->platform->switchTo(Backend::Api);
        $this->platform->useApiEndpoint($url);
        $this->platform->useApiKey($key);

        try {
            return $this->platform->isAvailable();
        } finally {
            $this->platform->useApiEndpoint($previousUrl);
            $this->platform->useApiKey($previousKey);
            $this->platform->switchTo($backend);
        }
    }

    private function envKeySet(): bool
    {
        return trim((string) ($_SERVER['SHERPA_API_KEY'] ?? $_ENV['SHERPA_API_KEY'] ?? getenv('SHERPA_API_KEY'))) !== '';
    }

    private function removeProvider(string $name): void
    {
        $name = ProviderName::normalize($name) ?? $name;

        if ($name === '' || !isset($this->models->providers()[$name])) {
            echo Terminal::RED . 'No such provider: ' . ($name === '' ? '(none given)' : $name) . "\n" . Terminal::RESET;
            echo Terminal::GRAY . "  /provider lists them.\n" . Terminal::RESET;

            return;
        }

        if ($name === $this->models->activeProvider()) {
            echo Terminal::RED . "{$name} is the provider in force.\n" . Terminal::RESET;
            echo Terminal::GRAY . "  Switch to another one first: /provider <name>.\n" . Terminal::RESET;

            return;
        }

        echo $this->models->removeProvider($name)
            ? Terminal::GREEN . "✓ Provider {$name} forgotten. /provider add to record it again.\n" . Terminal::RESET
            : Terminal::YELLOW . '⚠ Cannot write ' . $this->models->configPath() . "\n" . Terminal::RESET;
    }

    /**
     * Move to another provider, mid-session: /backend api with a different
     * address, key and model. Anything failing on the way leaves the session
     * where it was — on its provider, its key and its model.
     */
    public function switchProvider(string $name, MessageBag $bag, Project $project): void
    {
        $name = ProviderName::normalize($name) ?? $name;
        $providers = $this->models->providers();

        if (!isset($providers[$name])) {
            echo Terminal::RED . "No such provider: {$name}\n" . Terminal::RESET;
            echo Terminal::GRAY . '  ' . ($providers === []
                ? 'None recorded yet: /provider add.'
                : 'Known: ' . implode(', ', array_keys($providers)) . '. Or /provider add.') . "\n" . Terminal::RESET;

            return;
        }

        $backend = $this->platform->backend();
        $url = $this->platform->apiEndpoint();
        $key = $this->platform->apiKeyOverride();
        $forRun = $this->models->providerForRun();

        if ($backend === Backend::Api && $name === $this->models->activeProvider() && $url === $providers[$name]['url']) {
            $this->chat->showInfo("Already on {$name}.");

            return;
        }

        $this->models->useProvider($name);
        $this->platform->switchTo(Backend::Api);
        $this->configureApiEndpoint();

        $profile = null;
        if (!$this->platform->isAvailable()) {
            echo Terminal::RED . "✗ {$name} does not answer, or refuses the key (" . $this->platform->apiEndpoint() . ").\n" . Terminal::RESET;
            if (!$this->models->hasStoredKey($name) && ($variable = $providers[$name]['key_env'] ?? null) !== null) {
                echo Terminal::GRAY . "  Its key comes from {$variable}"
                    . ($this->platform->apiHasKey() ? '' : ', not set in this shell') . ".\n" . Terminal::RESET;
            }
            echo Terminal::GRAY . "  /provider key {$name} to give it a key.\n" . Terminal::RESET;
        } else {
            $stored = $this->models->stored(Backend::Api);
            $profile = $stored !== null
                ? $this->verified($stored)
                : $this->modelSelector->run(backend: Backend::Api);
        }

        if ($profile === null || $profile->model === '' || !$this->switchTo($profile, $bag, $project)) {
            $this->models->useProvider($forRun);
            $this->platform->useApiEndpoint($url);
            $this->platform->useApiKey($key);
            $this->platform->switchTo($backend);
            echo Terminal::GRAY . '  Still on ' . $this->whereItRuns($backend) . ".\n" . Terminal::RESET;

            return;
        }

        $this->settleEmbeddings(Backend::Api);
    }
    /**
     * Ask, but only when the answer could cost something: a model changed in
     * the first seconds discards nothing, one changed thirty turns in discards
     * thirty turns. Defaults to No, as everywhere something is destroyed — an
     * accidental Enter must not lose an afternoon's conversation.
     */
    private function confirmReset(MessageBag $bag, ModelProfile $profile): bool
    {
        $atStake = $bag->conversationSize();

        if ($atStake === 0) {
            return true;
        }

        echo Terminal::YELLOW . "\n  ⚠ Moving to {$profile->model} starts afresh: "
            . $atStake . ' message' . ($atStake > 1 ? 's' : '')
            . " of this conversation will be dropped.\n" . Terminal::RESET;
        echo Terminal::GRAY . "    The project's memory, its grants and the MCP servers stay.\n"
            . Terminal::RESET;

        return in_array(
            strtolower($this->lineEditor->ask('  Carry on? [y/N]: ', Terminal::BOLD)),
            ['o', 'oui', 'y', 'yes'],
            true,
        );
    }
    /**
     * Everything the previous model left behind, cleared: the conversation, the
     * chars-per-token calibration, the counts from the server. What stays is
     * not the model's — memory, grants, MCP servers — and saying which is which
     * matters: "cleared" beside a database of facts is alarming.
     */
    private function resetForNewModel(MessageBag $bag, Project $project): void
    {
        // Everything that discards something here says how much: "conversation
        // cleared" with no number is read twice, wondering what was lost.
        $discarded = $bag->conversationSize();

        // Rebuilt rather than reused: it is regenerated for every /reset too,
        // and rebuilding picks up any fact memorised since the session opened.
        $bag->replace([]);
        // The excerpts described a conversation that no longer exists, and the
        // ids the model was given to fetch them are in messages just discarded.
        $this->contextStore->clear();
        $bag->system($this->promptBuilder->build($project));
        $this->chat->clear();
        // The conversation just discarded stays on disk, resumable; what comes
        // next is a new one, and must not be written over it.
        $this->sessions->startNew();

        $this->chat->showInfo(sprintf(
            'Session started afresh for this model: %s, system prompt rebuilt, '
            . 'context estimate relearned over the next two turns.',
            $discarded === 0
                ? 'the conversation had not started'
                : $discarded . ' message' . ($discarded > 1 ? 's dropped' : ' dropped'),
        ));

        echo Terminal::GRAY . "  Kept: the project's memory, its grants, the MCP servers."
            . ($discarded > 0 ? "\n  The conversation dropped is still resumable with /resume." : '')
            . "\n" . Terminal::RESET;
    }
}
