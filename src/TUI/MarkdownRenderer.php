<?php

namespace App\TUI;

class MarkdownRenderer
{
    private bool $inCodeBlock = false;
    private string $codeLang = '';

    public function render(string $markdown): string
    {
        $this->inCodeBlock = false;
        $this->codeLang = '';

        $lines = explode("\n", $markdown);
        $output = [];

        foreach ($lines as $line) {
            $output[] = $this->renderLine($line);
        }

        return implode("\n", $output);
    }

    /**
     * Clear fence state between messages, so an unterminated ``` in one reply
     * does not swallow the next one.
     */
    public function reset(): void
    {
        $this->inCodeBlock = false;
        $this->codeLang = '';
    }

    /**
     * Render the trailing partial line at end of stream, preserving the current
     * fence state (unlike render(), which resets it).
     */
    public function renderRemainder(string $line): string
    {
        return $line === '' ? '' : $this->renderLine($line);
    }

    /**
     * Render incrementally (one token at a time), buffering lines.
     * Returns completed lines ready to print, keeping the last partial line.
     */
    public function renderIncremental(string &$buffer, string $newToken): string
    {
        $buffer .= $newToken;
        $lines = explode("\n", $buffer);
        $buffer = array_pop($lines); // keep last incomplete line

        $output = [];
        foreach ($lines as $line) {
            $output[] = $this->renderLine($line);
        }

        return implode("\n", $output) . (empty($output) ? '' : "\n");
    }

    private function renderLine(string $line): string
    {
        // Model output is untrusted text like any other: it is free to contain
        // escape sequences, and a 7B model echoing back a file it just read will
        // happily reproduce them. Strip before adding our own colours — this is
        // the one choke point every rendered line passes through.
        $line = Terminal::plain($line, singleLine: true);

        // Code block fence
        if (preg_match('/^```(\w*)/', $line, $m)) {
            if (!$this->inCodeBlock) {
                $this->inCodeBlock = true;
                $this->codeLang = $m[1] ?? '';
                $lang = $this->codeLang ? " {$this->codeLang}" : '';
                return Terminal::BG_DARK . Terminal::GRAY . "┌── code{$lang} " . Terminal::RESET;
            } else {
                $this->inCodeBlock = false;
                $this->codeLang = '';
                return Terminal::BG_DARK . Terminal::GRAY . "└────────" . Terminal::RESET;
            }
        }

        if ($this->inCodeBlock) {
            return Terminal::BG_DARK . Terminal::CYAN . '  ' . $line . Terminal::RESET;
        }

        // Headers
        if (preg_match('/^#{1,6}\s+(.+)/', $line, $m)) {
            $level = strlen($line) - strlen(ltrim($line, '#'));
            $colors = [
                1 => Terminal::BOLD . Terminal::YELLOW,
                2 => Terminal::BOLD . Terminal::CYAN,
                3 => Terminal::BOLD . Terminal::GREEN,
            ];
            $color = $colors[min($level, 3)];
            return $color . str_repeat('#', $level) . ' ' . $m[1] . Terminal::RESET;
        }

        // Horizontal rule
        if (preg_match('/^---+$/', trim($line))) {
            return Terminal::GRAY . str_repeat('─', 60) . Terminal::RESET;
        }

        // Blockquote
        if (str_starts_with(ltrim($line), '> ')) {
            $content = substr(ltrim($line), 2);
            return Terminal::GRAY . '▌ ' . Terminal::ITALIC . $this->renderInline($content) . Terminal::RESET;
        }

        // Unordered list
        if (preg_match('/^(\s*)[*\-+]\s+(.+)/', $line, $m)) {
            $indent = str_repeat(' ', strlen($m[1]));
            return $indent . Terminal::YELLOW . '•' . Terminal::RESET . ' ' . $this->renderInline($m[2]);
        }

        // Ordered list
        if (preg_match('/^(\s*)(\d+)\.\s+(.+)/', $line, $m)) {
            $indent = str_repeat(' ', strlen($m[1]));
            return $indent . Terminal::YELLOW . $m[2] . '.' . Terminal::RESET . ' ' . $this->renderInline($m[3]);
        }

        return $this->renderInline($line);
    }

    private function renderInline(string $text): string
    {
        // Bold
        $text = preg_replace('/\*\*(.+?)\*\*/', Terminal::BOLD . '$1' . Terminal::RESET, $text);
        // Italic
        $text = preg_replace('/\*(.+?)\*/', Terminal::ITALIC . '$1' . Terminal::RESET, $text);
        // Inline code
        $text = preg_replace('/`([^`]+)`/', Terminal::BG_DARK . Terminal::CYAN . ' $1 ' . Terminal::RESET, $text);
        // Links [text](url) → just text
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', Terminal::UNDERLINE . '$1' . Terminal::RESET, $text);

        return $text;
    }
}
