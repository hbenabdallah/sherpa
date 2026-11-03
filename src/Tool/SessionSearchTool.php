<?php

declare(strict_types=1);

namespace App\Tool;

use App\Agent\ContextBudget;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Session\SessionHit;
use App\Session\SessionSearch;

#[AsTool(
    name: 'session_search',
    description: "Search this project's earlier conversations (not this one) for what was asked and answered: a decision, an approach tried or dropped, why something was done. Keywords, in any order. For lasting facts use memory_recall; for code, project_grep.",
    permission: Permission::AUTO,
    // An earlier answer may repeat a web page or an MCP server's text: read
    // back, it is that text again, and taints the session as it did then.
    untrusted: true,
)]
final readonly class SessionSearchTool
{
    /** Share of the prompt window one search may occupy. */
    private const float SHARE = 0.06;

    private const int MIN_CHARS = 3000;

    private const int MAX_CHARS = 16000;

    /**
     * Said before, by the user or the model: a lead, not a fact. The code has
     * moved on since, and a model's answer then may have been wrong.
     */
    private const string HEADER = 'From earlier conversations of this project. What was said then, not necessarily true now: check it against the code before relying on it.';

    public function __construct(
        private SessionSearch $search,
        private ?ContextBudget $budget = null,
    ) {}

    public function __invoke(
        #[Param('Keywords to look for in earlier conversations, in any order')]
        string $search,
    ): string {
        if (trim($search) === '') {
            return 'Give keywords to look for in the earlier conversations.';
        }

        $hits = $this->search->search($search);
        if ($hits === null) {
            return 'No earlier conversation is saved for this project.';
        }
        if ($hits === []) {
            return "No earlier conversation mentions \"{$search}\".";
        }

        return $this->format($hits, $this->budget?->shareInChars(self::SHARE, self::MIN_CHARS, self::MAX_CHARS) ?? self::MIN_CHARS);
    }

    /** @param list<SessionHit> $hits */
    private function format(array $hits, int $budget): string
    {
        $bySession = [];
        foreach ($hits as $hit) {
            $bySession[$hit->session->id][] = $hit;
        }

        $out = self::HEADER;
        foreach ($bySession as $id => $sessionHits) {
            $session = $sessionHits[0]->session;
            $block = sprintf("\n\n## “%s” — %s (/resume %s)", $session->title, $session->updatedAt->format('Y-m-d'), $id);

            foreach ($sessionHits as $hit) {
                $block .= "\n[" . ($hit->role === 'user' ? 'user' : 'you') . "] {$hit->excerpt}";
                if ($hit->reply !== null) {
                    $block .= "\n[you, in reply] {$hit->reply}";
                }
            }

            if (mb_strlen($out) + mb_strlen($block) > $budget) {
                // The best match, cut, rather than nothing past the header.
                if ($out === self::HEADER) {
                    $out .= mb_substr($block, 0, max(0, $budget - mb_strlen($out) - 60)) . '…';
                }
                $out .= "\n\n… more matches did not fit; narrow the search.";

                break;
            }
            $out .= $block;
        }

        return $out;
    }
}
