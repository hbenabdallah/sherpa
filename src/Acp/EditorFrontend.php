<?php

namespace App\Acp;

use App\Agent\AgentOutput;
use App\Agent\Lanes;
use App\Agent\TodoList;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;
use App\Permission\Approver;
use App\Permission\ConfirmChoice;
use App\Permission\Offer;
use App\Project\ProjectPathResolver;
use App\Tool\Edit\EditPreview;
use App\Tool\Edit\ProposedEdit;
use App\Tool\ShellExecTool;
use App\TUI\ChatPane;
use App\TUI\Terminal;

/**
 * A turn as an editor sees it: session/update notifications for the reply and
 * the tools, session/request_permission for what needs a yes. The editor
 * draws all of it — the diff of an edit in its own diff view, the plan in its
 * own list — so what goes out is data, not Sherpa's rendering of it.
 */
final class EditorFrontend implements AgentOutput, Approver
{
    /** Tool output sent to the editor, at most: it shows it, the model has it all. */
    private const MAX_RESULT_CHARS = 20_000;

    /** Allowed for the session, or for good: both are an "always" to an editor. */
    private const OPTIONS = [
        ['optionId' => 'allow-once', 'name' => 'Allow once', 'kind' => 'allow_once'],
        ['optionId' => 'allow-session', 'name' => 'Allow for this session', 'kind' => 'allow_always'],
        ['optionId' => 'allow-project', 'name' => 'Always allow in this project', 'kind' => 'allow_always'],
        ['optionId' => 'reject', 'name' => 'Refuse', 'kind' => 'reject_once'],
    ];

    private const PLAN_OPTIONS = [
        ['optionId' => 'plan-go', 'name' => 'Carry out this plan', 'kind' => 'allow_once'],
        ['optionId' => 'plan-keep', 'name' => 'Keep planning', 'kind' => 'reject_once'],
    ];

    private string $sessionId = '';

    /** Whether the editor shows notices; otherwise Sherpa's remarks go as thoughts. */
    private bool $notices = false;

    private string $messageId = '';
    private string $withheld = '';
    private bool $holding = false;
    private string $lastWait = '';

    /**
     * The calls of this turn, by id: what they edit, taken before they run,
     * and the plan_ready call a plan is approved under.
     *
     * @var array<string, array{name: string, edit: ?ProposedEdit}>
     */
    private array $calls = [];
    private ?string $planCall = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly EditPreview $edits,
        private readonly ProjectPathResolver $paths,
        private readonly ShellExecTool $shell,
        private readonly TodoList $todos,
    ) {}

    public function forSession(string $sessionId, bool $notices): void
    {
        $this->sessionId = $sessionId;
        $this->notices = $notices;
        $this->calls = [];
        $this->planCall = null;
    }

    // ---- the reply ---------------------------------------------------------------

    public function beginAssistantMessage(): void
    {
        $this->messageId = self::newId('msg');
        $this->withheld = '';
        $this->holding = true;
        $this->lastWait = '';
    }

    /**
     * Held while the reply may still be a tool call written as prose, as the
     * terminal holds it: once sent, a chunk cannot be taken back.
     */
    public function appendToken(string $token): void
    {
        if (!$this->holding) {
            $this->chunk('agent_message_chunk', $token);

            return;
        }

        $this->withheld .= $token;
        $head = ltrim($this->withheld);
        if ($head === '' || ChatPane::couldBecomeToolCall($head)) {
            return;
        }

        $this->holding = false;
        $this->chunk('agent_message_chunk', $head);
        $this->withheld = '';
    }

    public function flushBuffer(bool $wasToolCall = false): void
    {
        if ($this->holding && !$wasToolCall && trim($this->withheld) !== '') {
            $this->chunk('agent_message_chunk', ltrim($this->withheld));
        }

        $this->holding = false;
        $this->withheld = '';
    }

    /**
     * A wait the model is not the cause of — a rate limit — said once, not at
     * every tick of its countdown.
     */
    public function showWaiting(bool $reasoning = false, ?string $why = null): void
    {
        if ($why === null) {
            return;
        }

        $gist = (string) preg_replace('/\d+/', '', $why);
        if ($gist === $this->lastWait) {
            return;
        }

        $this->lastWait = $gist;
        $this->remark($why);
    }

    public function showInfo(string $message): void
    {
        $this->remark($message);
    }

    /** In the conversation itself: an error is something to read, not a side note. */
    public function showError(string $message): void
    {
        $this->update([
            'sessionUpdate' => 'agent_message_chunk',
            'messageId'     => self::newId('msg'),
            'content'       => ['type' => 'text', 'text' => "\n\n> ⚠ " . str_replace("\n", "\n> ", Terminal::plain($message)) . "\n"],
        ]);
    }

    /** A reply that is not the model's: what a command says. */
    public function say(string $text): void
    {
        $this->update([
            'sessionUpdate' => 'agent_message_chunk',
            'messageId'     => self::newId('msg'),
            'content'       => ['type' => 'text', 'text' => $text],
        ]);
    }

    /** What a command printed, as the reply: a block, as the terminal lines it up. */
    public function showOutput(string $text): void
    {
        $this->say(self::fenced(Terminal::plain($text)));
    }

    public function forSubagent(): AgentOutput
    {
        return new SubagentThoughts($this->connection, $this->sessionId);
    }

    // ---- the tools --------------------------------------------------------------

    /** The editor shows the tool call as in progress already. */
    public function showRunning(string $toolName, float $seconds): void
    {
    }

    public function showToolCall(string $toolName, array $args = [], ?string $callId = null): void
    {
        $id = $callId ?? self::newId('call');

        // The plan is the editor's own list, sent with the result.
        if ($toolName === 'todo_write') {
            return;
        }

        // Before it runs, or the file read afterwards is already the new one.
        $edit = $this->edits->of(new ToolCall($id, $toolName, $args));
        $this->calls[$id] = ['name' => $toolName, 'edit' => $edit];

        if ($toolName === 'plan_ready') {
            $this->planCall = $id;
        }

        $update = [
            'sessionUpdate' => 'tool_call',
            'toolCallId'    => $id,
            'title'         => Lanes::prefix() . ToolDescriptions::title($toolName, $args),
            'kind'          => ToolDescriptions::kind($toolName),
            'status'        => 'pending',
            'rawInput'      => $args === [] ? new \stdClass() : $args,
        ];

        $locations = $this->locations($args, $edit);
        if ($locations !== []) {
            $update['locations'] = $locations;
        }

        $content = $this->preview($toolName, $args, $edit);
        if ($content !== []) {
            $update['content'] = $content;
        }

        $this->update($update);
    }

    public function showToolResult(string $toolName, string $result, bool $isError = false, ?string $callId = null): void
    {
        if ($toolName === 'todo_write' && !$isError) {
            $this->sendPlan();

            return;
        }

        $id = $callId ?? self::newId('call');
        $known = $this->calls[$id] ?? null;

        // A call refused before it was shown — its arguments cut off: it
        // still gets its card, failed.
        if ($known === null) {
            $this->update([
                'sessionUpdate' => 'tool_call',
                'toolCallId'    => $id,
                'title'         => ToolDescriptions::title($toolName, []),
                'kind'          => ToolDescriptions::kind($toolName),
                'status'        => 'failed',
                'content'       => [self::text(Terminal::plain($result))],
            ]);

            return;
        }

        $failed = $isError || preg_match('/^\((exit code [1-9]\d*|command failed|command interrupted)/', ltrim($result)) === 1;
        $content = [];
        if ($known['edit'] !== null && !$failed) {
            $content[] = self::diff($known['edit']);
        }
        if ($toolName !== 'plan_ready') {
            $content[] = self::text($this->resultText($toolName, $result));
        }

        $this->update([
            'sessionUpdate' => 'tool_call_update',
            'toolCallId'    => $id,
            'status'        => $failed ? 'failed' : 'completed',
            'content'       => $content,
        ]);
    }

    // ---- questions --------------------------------------------------------------

    public function confirm(ToolCall $call, ToolDefinition $def, Offer $offer): ConfirmChoice
    {
        $edit = $this->calls[$call->id]['edit'] ?? $this->edits->of($call);
        $content = $this->preview($call->name, $call->arguments, $edit) ?: [self::text($def->description)];
        if (!$offer->standing()) {
            $content[] = self::text("Asked every time: {$offer->onlyOnce}");
        }

        try {
            $answer = $this->connection->request('session/request_permission', [
                'sessionId' => $this->sessionId,
                'toolCall'  => [
                    'toolCallId' => $call->id,
                    'title'      => ToolDescriptions::title($call->name, $call->arguments),
                    'kind'       => ToolDescriptions::kind($call->name),
                    'status'     => 'pending',
                    'content'    => $content,
                ],
                'options' => self::options($offer),
            ]);
        } catch (AcpError) {
            // No answer is not a yes.
            return ConfirmChoice::Deny;
        }

        $chosen = self::chosen($answer);
        // Only what was offered: an answer naming another option is no answer.
        if (!in_array($chosen, array_column(self::options($offer), 'optionId'), true)) {
            return ConfirmChoice::Deny;
        }

        return match ($chosen) {
            'allow-once'    => ConfirmChoice::Once,
            'allow-session' => ConfirmChoice::Session,
            'allow-project' => ConfirmChoice::Project,
            default         => ConfirmChoice::Deny,
        };
    }

    /**
     * The answers offered: once and refuse only for what is asked every time,
     * and for a command, the commands a longer answer would grant, named.
     *
     * @return list<array{optionId: string, name: string, kind: string}>
     */
    private static function options(Offer $offer): array
    {
        if (!$offer->standing()) {
            return [self::OPTIONS[0], self::OPTIONS[3]];
        }
        if ($offer->grants === []) {
            return self::OPTIONS;
        }

        $commands = implode(', ', $offer->commands());
        $options = self::OPTIONS;
        $options[1]['name'] = "Allow {$commands} for this session";
        $options[2]['name'] = "Always allow {$commands} in this project";

        return $options;
    }

    /** The plan in the plan_ready card, and the question under it. */
    public function approvePlan(string $plan): ?bool
    {
        $id = $this->planCall ?? self::newId('call');
        $toolCall = [
            'toolCallId' => $id,
            'title'      => 'Plan ready',
            'kind'       => 'think',
            'status'     => 'pending',
            'content'    => [self::text(trim($plan))],
        ];

        $this->update(['sessionUpdate' => $this->planCall === null ? 'tool_call' : 'tool_call_update'] + $toolCall);

        try {
            $answer = $this->connection->request('session/request_permission', [
                'sessionId' => $this->sessionId,
                'toolCall'  => $toolCall,
                'options'   => self::PLAN_OPTIONS,
            ]);
        } catch (AcpError) {
            return null;
        }

        return match (self::chosen($answer)) {
            'plan-go' => true,
            null      => null,
            default   => false,
        };
    }

    // ---- what goes out -------------------------------------------------------------

    /** @param array<string, mixed> $update */
    private function update(array $update): void
    {
        $this->connection->notify('session/update', ['sessionId' => $this->sessionId, 'update' => $update]);
    }

    private function chunk(string $kind, string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->update(['sessionUpdate' => $kind, 'messageId' => $this->messageId, 'content' => ['type' => 'text', 'text' => $text]]);
    }

    /** Something Sherpa says on the side: a notice where the editor shows them, a thought elsewhere. */
    private function remark(string $message): void
    {
        $message = Terminal::plain($message);

        if ($this->notices) {
            $this->update(['sessionUpdate' => 'notice', 'severity' => 'info', 'title' => $message]);

            return;
        }

        $this->update([
            'sessionUpdate' => 'agent_thought_chunk',
            'messageId'     => self::newId('note'),
            'content'       => ['type' => 'text', 'text' => $message . "\n"],
        ]);
    }

    private function sendPlan(): void
    {
        $entries = array_map(static fn(array $task) => [
            'content'  => (string) $task['text'],
            'priority' => 'medium',
            'status'   => match ($task['status']) {
                TodoList::DONE  => 'completed',
                TodoList::DOING => 'in_progress',
                default         => 'pending',
            },
        ], $this->todos->tasks());

        $this->update(['sessionUpdate' => 'plan', 'entries' => $entries]);
    }

    /**
     * What the user should see before saying yes: the diff of an edit, the
     * command as it will run (in its container, if any).
     *
     * @param array<string, mixed> $args
     *
     * @return list<array<string, mixed>>
     */
    private function preview(string $toolName, array $args, ?ProposedEdit $edit): array
    {
        if ($edit !== null) {
            return [self::diff($edit)];
        }

        if ($toolName === 'shell_exec' && is_string($args['command'] ?? null)) {
            $cwd = is_string($args['cwd'] ?? null) ? $args['cwd'] : null;

            return [self::text(self::fenced($this->shell->describe($args['command'], $cwd)))];
        }

        return [];
    }

    /**
     * The files a call touches, absolute, for an editor that follows along.
     *
     * @param array<string, mixed> $args
     *
     * @return list<array{path: string, line?: int}>
     */
    private function locations(array $args, ?ProposedEdit $edit): array
    {
        if ($edit !== null) {
            return [['path' => $edit->path]];
        }

        $path = $args['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return [];
        }

        try {
            $location = ['path' => $this->paths->resolve($path)];
        } catch (\Throwable) {
            return [];
        }

        $line = $args['offset'] ?? null;
        if (is_int($line) && $line > 0) {
            $location['line'] = $line;
        }

        return [$location];
    }

    private function resultText(string $toolName, string $result): string
    {
        $text = Terminal::plain($result);
        if (mb_strlen($text) > self::MAX_RESULT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_RESULT_CHARS) . "\n… (" . number_format(mb_strlen($text) - self::MAX_RESULT_CHARS) . ' more characters, which the model has)';
        }

        return in_array(ToolDescriptions::kind($toolName), ['read', 'search', 'execute'], true) ? self::fenced($text) : $text;
    }

    /** @return array<string, mixed> */
    private static function diff(ProposedEdit $edit): array
    {
        return ['type' => 'diff', 'path' => $edit->path, 'oldText' => $edit->old, 'newText' => $edit->new];
    }

    /** @return array<string, mixed> */
    private static function text(string $text): array
    {
        return ['type' => 'content', 'content' => ['type' => 'text', 'text' => $text]];
    }

    /** As a code block, with a fence the text itself does not contain. */
    private static function fenced(string $text): string
    {
        $fence = '```';
        while (str_contains($text, $fence)) {
            $fence .= '`';
        }

        return "{$fence}\n" . rtrim($text, "\n") . "\n{$fence}";
    }

    /** The option picked, or null for a cancelled question or an answer that names none. */
    private static function chosen(mixed $answer): ?string
    {
        $outcome = is_array($answer) && is_array($answer['outcome'] ?? null) ? $answer['outcome'] : [];

        return ($outcome['outcome'] ?? null) === 'selected' && is_string($outcome['optionId'] ?? null) ? $outcome['optionId'] : null;
    }

    private static function newId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }
}
