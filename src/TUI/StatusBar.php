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

    public function renderWelcome(string $version = '0.1.0'): void
    {
        $banner = <<<EOT

  ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
  ░  ██████╗██╗  ██╗███████╗    ░
  ░ ██╔════╝██║  ██║██╔════╝    ░
  ░ ╚█████╗ ███████║█████╗      ░
  ░  ╚═══██╗██╔══██║██╔══╝      ░
  ░ ██████╔╝██║  ██║███████╗    ░
  ░ ╚═════╝ ╚═╝  ╚═╝╚══════╝    ░
  ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░

EOT;

        echo Terminal::CYAN . $banner . Terminal::RESET;
        echo Terminal::GRAY . "  Guide expert · Porte la charge cognitive · Montre le chemin\n" . Terminal::RESET;
        echo Terminal::GRAY . "  v{$version}\n\n" . Terminal::RESET;
    }
}
