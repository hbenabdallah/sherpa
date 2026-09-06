<?php

declare(strict_types=1);

namespace App\Command;

use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\InferenceSpeed;
use App\Agent\MessageBag;
use App\Agent\SystemPromptBuilder;
use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Mcp\McpRegistry;
use App\Memory\ContextStore;
use App\Memory\MemoryStore;
use App\Permission\ProjectPermissions;
use App\Permission\SessionPermissions;
use App\Platform\SwitchablePlatform;
use App\Project\Project;
use App\Project\ProjectQuestions;
use App\Project\WorkingProject;
use App\Rag\DocSearch;
use App\Session\SavedSession;
use App\Session\SessionStore;
use App\Skills\SkillRegistry;
use App\TUI\ChatPane;
use App\TUI\Terminal;
use App\Usage\UsageMeter;

/**
 * Everything typed with a slash in front of it.
 *
 * These are what the session is steered with — what it remembers, what it is
 * allowed to do, what it is spending, what it will forget — and they were a
 * three-hundred-line switch in the middle of the command that also boots the
 * session and runs its loop. Apart, they can be exercised without a terminal
 * and without a backend, which is how they are tested.
 *
 * handle() answers false for a word it does not know, and the caller says so:
 * an unknown slash command must not be sent to the model as a question.
 */
final class SlashCommands
{
    public function __construct(
        private readonly ChatPane $chat,
        private readonly MemoryStore $memoryStore,
        private readonly ContextStore $contextStore,
        private readonly SkillRegistry $skillRegistry,
        private readonly McpRegistry $mcp,
        private readonly Toolbox $toolbox,
        private readonly ContextBudget $budget,
        private readonly InferenceSpeed $speed,
        private readonly UsageMeter $usage,
        private readonly SwitchablePlatform $platform,
        private readonly ModelSession $model,
        private readonly ProjectPermissions $projectPermissions,
        private readonly SessionPermissions $sessionPermissions,
        private readonly HistoryCompactor $compactor,
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly SessionStore $sessions,
        private readonly WorkingProject $working,
        private readonly ProjectQuestions $questions,
        private readonly DocSearch $docs,
    ) {}

    public function handle(string $cmd, MessageBag $bag, Project $project): bool
    {
        $parts = explode(' ', $cmd, 2);
        $command = $parts[0];
        $args = $parts[1] ?? '';

        switch ($command) {
            case '/help':
                echo Terminal::CYAN . <<<HELP

  Commands:
    /help               Show this help
    /memory             List the facts remembered
    /remember <k> <v>   Remember a fact by hand
    /forget <key>       Drop a fact
    /model              Change model (list, or /model <name>)
    /backend            Online or local model (/backend api, /backend ollama)
    /skills             List the available skills
    /mcp                State of the configured MCP servers
    /permissions        List the project's standing grants
    /permissions revoke <tool>   Withdraw one grant
    /permissions clear  Withdraw every grant
    /context            Break down what fills the context
    /compact            Compact the conversation now
    /reset              Start an empty conversation (this one stays resumable)
    /sessions           List this project's saved conversations
    /resume [n]         Pick a conversation back up (the previous one, or the n-th of /sessions)
    /project            Show the current project (/project edit, /project forget)
    /docs               State of the documentation index (/docs reindex, /docs eval, /docs <question>)
    /tools              List the available tools
    /exit               Leave Sherpa

HELP . Terminal::RESET;
                return true;

            case '/memory':
                $facts = $this->memoryStore->recall(limit: 30);
                if (empty($facts)) {
                    $this->chat->showInfo('Nothing remembered yet.');
                } else {
                    echo Terminal::BOLD . "\nRemembered facts:\n" . Terminal::RESET;
                    foreach ($facts as $fact) {
                        $used = $fact->recalledCount > 0
                            ? Terminal::GRAY . "  ({$fact->recalledCount}×)" . Terminal::RESET
                            : '';
                        echo Terminal::YELLOW . "  • {$fact->key}: " . Terminal::RESET . $fact->value . $used . "\n";
                    }
                }

                // Does keyword search suffice? An empty recall on a project
                // that has facts is the model asking in words nobody filed.
                $stats = $this->memoryStore->recallStats();
                $searches = $stats['hits'] + $stats['misses'];
                if ($searches > 0) {
                    printf(
                        "\n  %d memory search(es) · %d with no result (%d %%)\n",
                        $searches,
                        $stats['misses'],
                        (int) round($stats['misses'] / $searches * 100),
                    );
                }

                // The same question, asked of the code: did a search find
                // anything, and was it reached in one go or guessed at.
                $code = $this->memoryStore->searchStats();
                $greps = $code['hits'] + $code['misses'];
                if ($greps > 0) {
                    printf(
                        "  %d code search(es) · %d with no result (%d %%)\n",
                        $greps,
                        $code['misses'],
                        (int) round($code['misses'] / $greps * 100),
                    );
                }

                $turns = $code['direct'] + $code['groping'];
                if ($turns > 0) {
                    printf(
                        "  %d search turn(s) · %d groping, 3 greps or more before a read (%d %%)\n",
                        $turns,
                        $code['groping'],
                        (int) round($code['groping'] / $turns * 100),
                    );
                }

                $kept = $this->contextStore->count();
                if ($kept > 0) {
                    echo Terminal::GRAY . "  {$kept} tool output(s) taken out of the context, "
                        . "still readable with context_recall.\n" . Terminal::RESET;
                }
                return true;

            case '/remember':
                $kv = explode(' ', $args, 2);
                if (count($kv) < 2) {
                    echo Terminal::RED . "Usage: /remember <key> <value>\n" . Terminal::RESET;
                } else {
                    // Remembering something here is deciding to work here: a
                    // held project is saved first, or the fact dies with the session.
                    $this->working->save();
                    $recorded = $this->memoryStore->remember($kv[0], $kv[1]);
                    echo Terminal::GREEN . match ($recorded) {
                        \App\Memory\Recorded::Created    => "✓ Remembered: {$kv[0]}\n",
                        \App\Memory\Recorded::Unchanged  => "✓ Already known: {$kv[0]}\n",
                        \App\Memory\Recorded::Superseded => "✓ Corrected: {$kv[0]}\n",
                    } . Terminal::RESET;
                }
                return true;

            case '/forget':
                if ($args === '') {
                    echo Terminal::RED . "Usage: /forget <key>\n" . Terminal::RESET;
                } elseif (($removed = $this->memoryStore->forget($args)) > 0) {
                    echo Terminal::GREEN . "✓ Forgotten: {$args} ({$removed} version" . ($removed > 1 ? 's' : '') . ")\n" . Terminal::RESET;
                } else {
                    echo Terminal::RED . "No such key: {$args}\n" . Terminal::RESET;
                }
                return true;

            case '/model':
                $this->model->handleModel(trim($args), $bag, $project);
                return true;

            case '/backend':
                $this->model->handleBackend(trim($args), $bag, $project);
                return true;

            case '/skills':
                $skills = $this->skillRegistry->all();
                if (empty($skills)) {
                    $this->chat->showInfo('No skills available. Add .md files in ~/.config/sherpa/skills/');
                } else {
                    echo Terminal::BOLD . "\nAvailable skills:\n" . Terminal::RESET;
                    foreach ($skills as $skill) {
                        echo Terminal::YELLOW . "  • {$skill->name}: " . Terminal::RESET . $skill->description . "\n";
                    }
                }
                return true;

            case '/permissions':
                $this->handlePermissions($args);
                return true;

            case '/mcp':
                $report = $this->mcp->report();
                if ($report === []) {
                    $this->chat->showInfo('No MCP server configured. Declare them in ~/.config/sherpa/mcp.json.');

                    return true;
                }

                echo Terminal::BOLD . "\nMCP servers:\n" . Terminal::RESET;
                foreach ($report as $line) {
                    echo Terminal::YELLOW . '  • ' . Terminal::RESET . Terminal::plain($line) . "\n";
                }

                return true;

            case '/tools':
                echo Terminal::BOLD . "\nAvailable tools:\n" . Terminal::RESET;
                foreach ($this->toolbox->getDefinitions() as $def) {
                    $colour = match ($def->permission) {
                        Permission::AUTO    => Terminal::GRAY,
                        Permission::CONFIRM => Terminal::YELLOW,
                        Permission::DENY    => Terminal::RED,
                    };
                    printf(
                        "  %s%-18s %-8s%s %s\n",
                        $colour,
                        $def->name,
                        $def->permission->name,
                        Terminal::RESET,
                        Terminal::GRAY . mb_substr($def->description, 0, 60) . Terminal::RESET,
                    );
                }
                return true;

            case '/context':
                $schemas = $this->toolbox->schemas();
                $estimated = $this->budget->estimateRequest($bag->all(), $schemas);
                echo Terminal::BOLD . "\nContext:\n" . Terminal::RESET;
                $this->row('model', "%s", $this->platform->modelName());
                $this->row('backend', "%s", $this->model->whereItRuns($this->platform->backend()));
                $this->row('chosen from', "%s", $this->model->profile()?->source ?? '(unknown)');
                $this->row('messages', "%d", $bag->count());
                $this->row('history', "~%d tokens", $this->budget->estimateMessages($bag->all()));
                $this->row('tool schemas', "~%d tokens", $this->budget->estimateTools($schemas));
                $this->row('estimated total', "~%d tokens", $estimated);
                $this->row('compaction threshold', "%d tokens", $this->budget->compactThreshold());
                $this->row('compaction target', "%d tokens", $this->budget->compactTarget());
                $this->row('model window', "%d tokens", $this->budget->contextWindow());
                $this->row(
                    'last measured',
                    '%s',
                    $this->budget->lastMeasured() !== null
                        ? $this->budget->lastMeasured() . ' tokens (from the server)'
                        : '(not measured yet)',
                );
                $this->row('chars/token calibrated', "%.2f", $this->budget->charsPerToken());

                // Measured, never configured: read from the server for a
                // local model, timed here for an API.
                echo Terminal::BOLD . "\nMeasured throughput:\n" . Terminal::RESET;
                $this->row(
                    'prefill',
                    '%s',
                    $this->speed->prefillTokensPerSecond() !== null
                        ? sprintf('%.0f tokens/s', $this->speed->prefillTokensPerSecond())
                        : '(not measured yet)',
                );
                $this->row(
                    'generation',
                    '%s',
                    $this->speed->decodeTokensPerSecond() !== null
                        ? sprintf('%.1f tokens/s', $this->speed->decodeTokensPerSecond())
                        : '(not measured yet)',
                );

                if ($this->platform->backend()->isLocal()) {
                    $residency = $this->platform->residency();
                    $this->row('model residency', "%s", $residency?->describe() ?? '(unknown)');
                }

                $reprefill = $this->speed->secondsToPrefill($estimated);
                if ($reprefill !== null) {
                    $this->row('context re-read', "~%.1f s", $reprefill);
                }

                $this->showConsumption();
                return true;

            case '/reset':
                // Rebuild the system message from scratch: refreshed facts
                // and skills are picked up.
                $bag->replace([]);
                // The excerpts were this conversation's; they go with it.
                $this->contextStore->clear();
                $bag->system($this->promptBuilder->build($project));
                // The one just left is already on disk, saved after its last
                // turn, and stays resumable.
                $this->sessions->startNew();
                echo Terminal::GREEN . "✓ History cleared." . Terminal::RESET
                    . Terminal::GRAY . " The previous conversation is still resumable with /resume.\n" . Terminal::RESET;
                return true;

            case '/docs':
                $this->docs(trim($args));
                return true;

            case '/sessions':
                $this->listSessions();
                return true;

            case '/resume':
                $this->resume(trim($args), $bag, $project);
                return true;

            case '/project':
                if (trim($args) === 'forget') {
                    $this->forgetProject();

                    return true;
                }

                if (trim($args) === 'edit') {
                    // A draft is re-asked as a draft: editing it is not yet
                    // deciding to work here, so nothing is written.
                    $this->working->replace($this->working->isSaved()
                        ? $this->questions->edit($this->working->project())
                        : $this->questions->ask($this->working->project()->path));
                    echo Terminal::GREEN . "✓ Project updated.\n" . Terminal::RESET;

                    return true;
                }

                $project = $this->working->project();
                echo Terminal::BOLD . "\nCurrent project:\n" . Terminal::RESET;
                echo Terminal::YELLOW . "  Name:   " . Terminal::RESET . $project->name . "\n";
                echo Terminal::YELLOW . "  Path:   " . Terminal::RESET . $project->path . "\n";
                echo Terminal::YELLOW . "  Stack:  " . Terminal::RESET . $project->stack . "\n";
                echo Terminal::YELLOW . "  Docker: " . Terminal::RESET . ($project->docker->enabled ? "yes ({$project->docker->container})" : "no") . "\n";
                if (!$this->working->isSaved()) {
                    echo Terminal::GRAY . "  Not saved yet: it will be, at your first message.\n" . Terminal::RESET;
                }
                echo Terminal::GRAY . "  /project edit to change its name or Docker · /project forget to drop it\n" . Terminal::RESET;
                return true;

            case '/compact':
                $this->chat->showInfo('Compacting the conversation...');
                $before = $this->budget->estimateRequest($bag->all(), $this->toolbox->schemas());
                $actions = $this->compactor->compact($bag, $this->toolbox->schemas());
                $after = $this->budget->estimateRequest($bag->all(), $this->toolbox->schemas());

                if ($actions === []) {
                    $this->chat->showInfo('Nothing to compact.');
                } else {
                    printf(
                        "%s✓ %s : ~%d → ~%d tokens.%s\n",
                        Terminal::GREEN,
                        implode(', ', $actions),
                        $before,
                        $after,
                        Terminal::RESET,
                    );
                }
                return true;
        }

        return false;
    }
    /**
     * List and withdraw the standing per-project grants: one that takes editing
     * YAML to undo is one that never gets undone.
     */
    private function handlePermissions(string $args): void
    {
        $parts = explode(' ', trim($args), 2);
        $action = $parts[0] ?? '';
        $target = trim($parts[1] ?? '');

        if ($action === 'clear') {
            $count = $this->projectPermissions->revokeAll();
            echo $count === 0
                ? Terminal::GRAY . "  No grant to withdraw.\n" . Terminal::RESET
                : Terminal::GREEN . "✓ {$count} grant(s) withdrawn.\n" . Terminal::RESET;

            return;
        }

        if ($action === 'revoke') {
            if ($target === '') {
                echo Terminal::RED . "Usage: /permissions revoke <tool>\n" . Terminal::RESET;
            } elseif ($this->projectPermissions->revoke($target)) {
                echo Terminal::GREEN . "✓ Withdrawn: {$target}. It will be asked about again.\n" . Terminal::RESET;
            } else {
                echo Terminal::RED . "Not a standing grant: {$target}\n" . Terminal::RESET;
            }

            return;
        }

        $standing = $this->projectPermissions->all();
        $session = $this->sessionPermissions->all();

        echo Terminal::BOLD . "\nGrants\n" . Terminal::RESET;

        echo Terminal::YELLOW . "  Standing (project) " . Terminal::RESET;
        echo $standing === []
            ? Terminal::GRAY . "— none\n" . Terminal::RESET
            : "\n" . Terminal::RESET;
        foreach ($standing as $tool) {
            echo Terminal::YELLOW . "    • {$tool}\n" . Terminal::RESET;
        }

        echo Terminal::CYAN . "  This session only " . Terminal::RESET;
        echo $session === []
            ? Terminal::GRAY . "— none\n" . Terminal::RESET
            : "\n" . Terminal::RESET;
        foreach ($session as $tool) {
            echo Terminal::CYAN . "    • {$tool}\n" . Terminal::RESET;
        }

        if ($standing !== []) {
            echo Terminal::GRAY . "\n  /permissions revoke <tool> · /permissions clear\n" . Terminal::RESET;
        }
    }
    /** What this session used and cost — the compactor's requests included. */
    private function showConsumption(): void
    {
        echo Terminal::BOLD . "\nSession usage:\n" . Terminal::RESET;

        $this->row('requests', '%d', $this->usage->requests());
        $this->row(
            'tokens sent',
            '%s',
            UsageMeter::number($this->usage->prompt())
                . ($this->usage->cached() > 0 ? ' (' . UsageMeter::number($this->usage->cached()) . ' of them cached)' : ''),
        );
        $this->row('tokens received', '%s', UsageMeter::number($this->usage->completion()));

        $cost = $this->usage->costLabel();
        $this->row('estimated cost', '%s', match (true) {
            $cost !== null && $this->usage->unpriced() > 0 => "{$cost} ({$this->usage->unpriced()} unpriced request(s) left out)",
            $cost !== null => $cost,
            $this->platform->backend()->isLocal() => 'none (local model)',
            default => '(no price for this model — prices in config.yaml)',
        });

        $cap = $this->usage->cap();
        $this->row('cap', '%s', $cap > 0
            ? sprintf('%s tokens (%d %% used)', UsageMeter::number($cap), (int) round($this->usage->total() / $cap * 100))
            : 'none (SHERPA_MAX_SESSION_TOKENS)');
    }
    /**
     * /docs: what the documentation index holds; /docs reindex to read it all
     * again; /docs <question> to see exactly what the model would be given —
     * the first thing to look at when an answer cites the wrong passage.
     */
    private function docs(string $args): void
    {
        if ($args === 'eval') {
            $this->evaluateDocs();

            return;
        }

        if ($args !== '' && $args !== 'reindex') {
            echo "\n" . Terminal::plain($this->docs->search($args)) . "\n";

            return;
        }

        $report = $args === 'reindex' ? $this->docs->rebuild() : $this->docs->sync();
        $counts = $this->docs->counts();

        echo Terminal::BOLD . "\nProject documentation:\n" . Terminal::RESET;
        $this->row('documents', '%d', $counts['documents']);
        $this->row('indexed passages', '%d', $counts['chunks']);
        $mode = $this->docs->mode();
        $this->row('search', '%s', $mode['mode'] . ' — ' . $mode['detail']);
        $this->row($args === 'reindex' ? 'reindexed' : 'synced', '%s', $report->summary());
        foreach ($report->failed as $path => $why) {
            echo Terminal::YELLOW . "  ! {$path} : {$why}\n" . Terminal::RESET;
        }

        if ($counts['documents'] === 0) {
            echo Terminal::GRAY . "  No document read (Markdown, text, reStructuredText, AsciiDoc, HTML).\n" . Terminal::RESET;
        }
        echo Terminal::GRAY . "  /docs <question> shows what the model would be given · /docs reindex reads it all again\n" . Terminal::RESET;
    }

    /**
     * /docs eval: the search measured on questions written for this project.
     *
     * The questions live in the project — .sherpa/rag-questions.json, beside
     * its skills — so they travel with the documentation they test, and a team
     * can review them like anything else in the repository.
     */
    private function evaluateDocs(): void
    {
        $file = DocSearch::questionsFor($this->working->project());

        if (!is_file($file)) {
            $this->chat->showInfo("No evaluation questions: create {$file}");
            echo Terminal::GRAY . <<<'HELP'
  Real questions, each with the section that answers it — for example:

    [
      {"kind": "procedure", "q": "How do we deploy to staging?",
       "expect": [{"path": "docs/deploy.md", "section": "Staging"}]},
      {"kind": "french", "q": "Comment lance-t-on les tests ?",
       "expect": [{"path": "README.md", "section": ""}]}
    ]

  "kind" groups the results; "section" is part of the expected heading, empty
  for "anywhere in this file". Twenty questions already say a lot.

HELP . Terminal::RESET;

            return;
        }

        $questions = json_decode((string) file_get_contents($file), true);
        if (!is_array($questions) || $questions === [] || !isset($questions[0]['q'], $questions[0]['expect'])) {
            $this->chat->showError("{$file} does not hold a readable list of questions (see /docs eval with no file).");

            return;
        }

        $questions = array_map(static fn(array $q) => ['kind' => (string) ($q['kind'] ?? 'question')] + $q, $questions);
        $results = $this->docs->evaluate($questions);

        echo Terminal::BOLD . "\nDocumentation search, measured on " . count($questions) . " questions:\n\n" . Terminal::RESET;
        echo \App\Rag\Eval\RetrievalEval::table($results) . "\n\n";

        foreach ($results as $name => $result) {
            foreach ($result['misses'] as $miss) {
                echo Terminal::YELLOW . "  missed ({$name}): " . Terminal::RESET . Terminal::plain($miss['q'], singleLine: true) . "\n"
                    . Terminal::GRAY . '      → ' . Terminal::plain($miss['top'], singleLine: true) . "\n" . Terminal::RESET;
            }
        }
    }

    /**
     * /project forget: the project's entry, memory and conversations deleted,
     * after one question that says how much that is.
     */
    private function forgetProject(): void
    {
        if (!$this->working->isSaved()) {
            $this->chat->showInfo('This directory is not a project yet: nothing to forget.');

            return;
        }

        $project = $this->working->project();

        if (!$this->questions->confirmForget($project, $this->memoryStore->count(), count($this->sessions->all()))) {
            echo Terminal::GRAY . "  Nothing was deleted.\n" . Terminal::RESET;

            return;
        }

        $this->working->forget();

        echo Terminal::GREEN . "✓ \"{$project->name}\" forgotten." . Terminal::RESET
            . Terminal::GRAY . " Its memory and conversations are deleted; the directory is untouched.\n"
            . "  Started here again, Sherpa asks its two questions afresh.\n" . Terminal::RESET;
    }

    /**
     * Write the conversation down as it stands, after every turn and on the
     * way out: a crash costs one turn at most.
     *
     * @return bool false when there was nothing to save, or it failed
     */
    public function saveSession(MessageBag $bag): bool
    {
        return $this->sessions->save(
            $bag->all(),
            $this->contextStore->export(),
            $this->platform->backend()->value,
            $this->platform->modelName(),
        );
    }

    /**
     * Pick a conversation back up: the previous one, the n-th of /sessions, or
     * one named by its id. Nothing is confirmed because nothing is lost — the
     * one being left is on disk too, and /resume brings it back as easily.
     */
    public function resume(string $which, MessageBag $bag, Project $project): bool
    {
        $session = $this->pick($which);

        if ($session === null) {
            $this->chat->showInfo($which === ''
                ? 'No other conversation saved for this project.'
                : "No conversation \"{$which}\". /sessions lists them.");

            return false;
        }

        if ($session->id === $this->sessions->currentId()) {
            $this->chat->showInfo('That is already the conversation you are in.');

            return false;
        }

        $this->saveSession($bag);

        $bag->replace([]);
        $bag->system($this->promptBuilder->build($project) . $this->resumeNote($session));
        foreach ($session->messages as $message) {
            $bag->add($message);
        }
        // Under their own ids: the stubs in these messages quote them.
        $this->contextStore->import($session->excerpts);
        $this->sessions->adopt($session);
        // Resuming is activity: unsaved, the conversation just left would top
        // /sessions, and a bare /resume would not go back where you were.
        $this->saveSession($bag);

        echo Terminal::GREEN . '✓ Conversation resumed: ' . Terminal::RESET
            . '"' . Terminal::plain($session->title, singleLine: true) . '"'
            . Terminal::GRAY . sprintf(
                ' — %d messages, started %s.',
                $session->size(),
                $session->startedAt->format('m-d H:i'),
            ) . "\n" . Terminal::RESET;

        // Another model reading this history is not what it was written for.
        // The user's call, but said rather than left to be noticed.
        $now = $this->platform->modelName();
        if ($session->model !== '' && $session->model !== $now) {
            echo Terminal::YELLOW . "  ⚠ Held with {$session->model}, it carries on with {$now}.\n" . Terminal::RESET;
        }

        $this->recap($session);

        return true;
    }

    private function listSessions(): void
    {
        $sessions = $this->sessions->all();

        if ($sessions === []) {
            $this->chat->showInfo('No conversation saved for this project.');

            return;
        }

        echo Terminal::BOLD . "\nThis project's conversations:\n" . Terminal::RESET;

        foreach ($sessions as $i => $session) {
            $current = $session->id === $this->sessions->currentId();

            echo sprintf(
                "  %s%2d.%s %s  “%s”%s · %d messages%s\n",
                Terminal::YELLOW,
                $i + 1,
                Terminal::RESET,
                $session->updatedAt->format('m-d H:i'),
                Terminal::plain(mb_strimwidth($session->title, 0, 60, '…'), singleLine: true),
                Terminal::GRAY,
                $session->size(),
                $current ? Terminal::RESET . Terminal::GREEN . '  ← current' . Terminal::RESET : Terminal::RESET,
            );
        }

        echo Terminal::GRAY . "\n  /resume <n> to pick one back up · the "
            . SessionStore::KEEP . " most recent are kept\n" . Terminal::RESET;
    }

    /** '' → the previous one; a number → that line of /sessions; else an id. */
    private function pick(string $which): ?SavedSession
    {
        if ($which === '') {
            return $this->sessions->latest();
        }

        if (ctype_digit($which)) {
            return $this->sessions->all()[(int) $which - 1] ?? null;
        }

        return $this->sessions->load($which);
    }

    /**
     * What the model is told about a history it did not just write: the files
     * in it are as they were, and a patch aimed at yesterday's version fails —
     * or worse, applies. Part of the system prompt, not of what the user said.
     */
    private function resumeNote(SavedSession $session): string
    {
        return "\n\n# Resumed conversation\n"
            . 'This conversation was started on ' . $session->startedAt->format('Y-m-d H:i')
            . ' and resumed on ' . (new \DateTimeImmutable())->format('Y-m-d H:i') . ".\n"
            . "The project's files may have changed since: read a file again before relying on\n"
            . "what you read of it, and always before changing it.";
    }

    /** Where the conversation stopped: the history itself is not on screen. */
    private function recap(SavedSession $session): void
    {
        $shorten = static function (string $text, int $width): string {
            $line = trim((string) preg_replace('/\s+/', ' ', $text));

            return mb_strlen($line) > $width ? mb_substr($line, 0, $width - 1) . '…' : $line;
        };

        $question = $session->lastQuestion();
        $answer = $session->lastAnswer();

        if ($question !== '') {
            echo Terminal::GRAY . '  Last request: ' . Terminal::plain($shorten($question, 100), singleLine: true) . "\n" . Terminal::RESET;
        }
        if ($answer !== '') {
            echo Terminal::GRAY . '  Last answer:  ' . Terminal::plain($shorten($answer, 100), singleLine: true) . "\n" . Terminal::RESET;
        }
    }

    /**
     * One aligned "label  value" line of /context. Padded by characters, not
     * bytes: printf's %-22s counts an accented letter twice.
     */
    private function row(string $label, string $format, mixed ...$values): void
    {
        echo '  ' . mb_str_pad($label, 22) . ' ' . vsprintf($format, $values) . "\n";
    }
}
