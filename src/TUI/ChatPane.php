<?php

namespace App\TUI;

class ChatPane
{
    private array $history = [];
    private string $spinner = '⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏';
    private int $spinnerFrame = 0;

    /** Trailing partial line of the reply being streamed, not yet terminated by "\n". */
    private string $streamBuffer = '';

    /** The "Sherpa" label is owed but not yet printed. See beginAssistantMessage(). */
    private bool $labelPending = false;

    /**
     * Tokens printed nowhere yet because the reply may turn out to be a tool
     * call written as prose. See appendToken().
     */
    private string $withheld = '';
    private bool $holding = true;

    public function __construct(
        private readonly Terminal $terminal,
        private readonly MarkdownRenderer $markdown,
    ) {}

    public function clear(): void
    {
        $this->history = [];
    }

    public function addUserMessage(string $content): void
    {
        $this->history[] = ['role' => 'user', 'content' => $content];
        $this->renderLast();
    }

    /**
     * The label is deferred rather than printed here: a turn that produces only
     * tool calls streams no text at all, and printing eagerly leaves an orphan
     * "Sherpa" hanging above the tool line with nothing under it.
     */
    public function beginAssistantMessage(): void
    {
        $this->streamBuffer = '';
        $this->labelPending = true;
        $this->withheld = '';
        $this->holding = true;
        $this->markdown->reset();
    }

    /**
     * Small models routinely emit a tool call as prose rather than in the
     * structured field, and AgentLoop recovers it — but only once the stream has
     * ended, by which point the raw {"name": …} has already been printed. So the
     * opening of a reply that looks like one is held back until the verdict is
     * in. Ordinary prose settles the question on its first character and streams
     * normally; only the case that would have looked wrong pays any latency.
     */
    public function appendToken(string $token): void
    {
        if (!$this->holding) {
            $this->emitRendered($token);

            return;
        }

        $this->withheld .= $token;
        $head = ltrim($this->withheld);

        if ($head === '' || $this->couldBecomeToolCall($head)) {
            return;
        }

        $this->holding = false;
        $held = $this->withheld;
        $this->withheld = '';
        $this->emitRendered($held);
    }

    private function couldBecomeToolCall(string $head): bool
    {
        if ($head[0] === '{') {
            return true;
        }

        // The tag itself may still be arriving a token at a time.
        return str_starts_with($head, '<tool_call>') || str_starts_with('<tool_call>', $head);
    }

    private function emitRendered(string $text): void
    {
        $rendered = $this->markdown->renderIncremental($this->streamBuffer, $text);
        if ($rendered === '') {
            return;
        }

        $this->emitLabel();
        echo $rendered;
    }

    /**
     * Emit the trailing partial line. A reply rarely ends with "\n", so without
     * this its final line would never be printed.
     */
    public function flushBuffer(bool $wasToolCall = false): void
    {
        if ($this->holding) {
            $this->holding = false;
            $held = $this->withheld;
            $this->withheld = '';

            // It really was a tool call: it has already been parsed and is about
            // to be shown as "→ Tool: …", so the raw form is dropped entirely.
            if (!$wasToolCall) {
                $this->emitRendered($held);
            }
        }

        if ($this->streamBuffer !== '') {
            $this->emitLabel();
            echo $this->markdown->renderRemainder($this->streamBuffer);
            $this->streamBuffer = '';
        }

        // Nothing was streamed — no label was printed, so there is no line to
        // close either.
        if (!$this->labelPending) {
            echo "\n";
        }

        $this->labelPending = false;
    }

    private function emitLabel(): void
    {
        if (!$this->labelPending) {
            return;
        }

        $this->labelPending = false;
        echo Terminal::BOLD . Terminal::CYAN . "\nSherpa " . Terminal::RESET;
    }

    public function addAssistantMessage(string $content): void
    {
        $this->history[] = ['role' => 'assistant', 'content' => $content];
        echo Terminal::BOLD . Terminal::CYAN . "\nSherpa " . Terminal::RESET;
        echo $this->markdown->render($content);
        echo "\n";
    }

    public function showToolCall(string $toolName, array $args = []): void
    {
        $argStr = '';
        foreach ($args as $k => $v) {
            $val = is_string($v) ? $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE);
            $val = mb_substr(Terminal::plain($val, singleLine: true), 0, 50);
            $argStr .= ' ' . Terminal::plain((string) $k, singleLine: true)
                . '=' . Terminal::ITALIC . $val . Terminal::RESET . Terminal::GRAY;
        }

        echo Terminal::GRAY . '  → Tool: ' . Terminal::YELLOW . Terminal::plain($toolName, singleLine: true)
            . Terminal::GRAY . $argStr . Terminal::RESET . "\n";
    }

    /**
     * Tool output is the least trustworthy text on screen — it is whatever
     * happened to be on disk or on stdout — so it is stripped before display.
     */
    public function showToolResult(string $toolName, string $result, bool $isError = false): void
    {
        $clean = Terminal::plain($result);
        $first = explode("\n", $clean)[0] ?? '';
        $truncated = mb_strlen($clean) > mb_strlen($first) ? ' …' : '';

        $color = $isError ? Terminal::RED : Terminal::GRAY;
        echo $color . '     ' . ($isError ? '✗' : '✓') . ' '
            . mb_substr($first, 0, 200) . $truncated . Terminal::RESET . "\n";
    }

    public function showSpinner(string $message = 'Sherpa réfléchit...'): void
    {
        $frame = mb_substr($this->spinner, $this->spinnerFrame % mb_strlen($this->spinner), 1);
        $this->spinnerFrame++;
        echo "\r" . Terminal::CYAN . $frame . Terminal::RESET . " {$message}  ";
    }

    public function clearSpinner(): void
    {
        echo "\r" . str_repeat(' ', 60) . "\r";
    }

    public function showError(string $message): void
    {
        echo Terminal::RED . Terminal::BOLD . "\n✗ " . Terminal::plain($message) . Terminal::RESET . "\n";
    }

    public function showInfo(string $message): void
    {
        echo Terminal::GRAY . "\n  " . Terminal::plain($message) . Terminal::RESET . "\n";
    }

    private function renderLast(): void
    {
        $msg = end($this->history);
        if ($msg === false) {
            return;
        }

        if ($msg['role'] === 'user') {
            echo "\n" . Terminal::BOLD . Terminal::GREEN . 'Vous ' . Terminal::RESET
                . Terminal::WHITE . Terminal::plain($msg['content'], singleLine: true)
                . Terminal::RESET . "\n";
        }
    }
}
