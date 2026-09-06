<?php

namespace App\TUI;

use App\Project\Project;

class StatusBar
{
    public function __construct(private readonly Terminal $terminal) {}

    /**
     * One-line status printed above the prompt.
     *
     * @param int  $used        estimated prompt tokens for the next request
     * @param int  $limit       tokens available before compaction kicks in
     * @param bool $exactCounts whether $used came from the server's tokenizer
     *                          rather than the character-based estimate
     */
    public function render(
        Project $project,
        string $model,
        int $used = 0,
        int $limit = 0,
        bool $exactCounts = false,
        // What the session has consumed so far, already formatted; null
        // before the first request.
        ?string $usage = null,
    ): void {
        $parts = [
            Terminal::BOLD . Terminal::plain($project->name, singleLine: true) . Terminal::RESET . Terminal::GRAY,
            Terminal::plain($model, singleLine: true),
        ];

        if ($project->docker->enabled && $project->docker->container !== '') {
            $parts[] = '🐳 ' . Terminal::plain($project->docker->container, singleLine: true);
        }

        if ($limit > 0) {
            $percent = (int) round($used / $limit * 100);

            // Escalate before it becomes a problem: past the compaction
            // threshold the next turn will spend time rewriting history.
            $colour = match (true) {
                $percent >= 90 => Terminal::RED,
                $percent >= 75 => Terminal::YELLOW,
                default        => Terminal::GRAY,
            };

            $parts[] = $colour . sprintf(
                '%sctx %s/%s (%d%%)',
                $exactCounts ? '' : '~',
                $this->humanTokens($used),
                $this->humanTokens($limit),
                $percent,
            );
        }

        if ($usage !== null) {
            $parts[] = $usage;
        }

        // The blank line belongs here, not to the prompt: this is what separates
        // one turn from the next, and the prompt has to sit tight under the bar
        // or readline redraws the line a row away from where it was typed.
        echo "\n" . Terminal::GRAY . '  ' . implode(' · ', $parts) . Terminal::RESET . "\n";
    }

    private function humanTokens(int $tokens): string
    {
        return $tokens >= 1000
            ? rtrim(rtrim(number_format($tokens / 1000, 1, '.', ''), '0'), '.') . 'k'
            : (string) $tokens;
    }

    public function renderWelcome(?string $version = null): void
    {
        $s = Terminal::SLATE;
        $l = Terminal::LIME;
        $r = Terminal::RESET;

        // The logo of public/sherpa.jpeg: two peaks, the lime one in front.
        echo "\n"
            . "                            {$l}▄█▄{$r}\n"
            . "                     {$s}▄█▄  {$l}▄█▀ ▀█▄{$r}\n"
            . "                   {$s}▄█▀ ▀{$l}▄█▀     ▀█▄{$r}\n\n";

        $wordmark = <<<'EOT'
  ███████╗██╗  ██╗███████╗██████╗ ██████╗  █████╗
  ██╔════╝██║  ██║██╔════╝██╔══██╗██╔══██╗██╔══██╗
  ███████╗███████║█████╗  ██████╔╝██████╔╝███████║
  ╚════██║██╔══██║██╔══╝  ██╔══██╗██╔═══╝ ██╔══██║
  ███████║██║  ██║███████╗██║  ██║██║     ██║  ██║
  ╚══════╝╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝╚═╝     ╚═╝  ╚═╝

EOT;

        echo Terminal::BOLD . $s . $wordmark . $r;
        echo "\n  {$l}›{$r} " . Terminal::GRAY . "Understand your code · Plan & reason · Build with you\n" . $r;
        // "v0.1.5" for a release; a checkout says "dev (…)" as it is.
        $version ??= \App\Version::current();
        echo Terminal::GRAY . '  ' . (ctype_digit($version[0] ?? '') ? 'v' : '') . $version . "\n\n" . $r;
    }
}
