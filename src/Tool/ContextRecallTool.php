<?php

namespace App\Tool;

use App\Agent\ContextBudget;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Memory\ContextStore;

#[AsTool(
    name: 'context_recall',
    description: 'Search the tool output that was removed from this conversation to save context. Use it instead of re-running a tool when you need something you read earlier in this session. Search by keywords, or pass the id quoted in an elided message.',
    permission: Permission::AUTO,
)]
class ContextRecallTool
{
    /** Share of the prompt window one recall may occupy. */
    private const SHARE = 0.08;
    private const MIN_CHARS = 4000;
    private const MAX_CHARS = 40000;

    public function __construct(
        private readonly ContextStore $store,
        private readonly ?ContextBudget $budget = null,
    ) {}

    public function __invoke(
        #[Param('Keywords to look for in earlier tool output, in any order')] ?string $search = null,
        #[Param('Id of an elided output, as quoted in the "[… elided]" message (optional)')] ?int $id = null,
    ): string {
        if ($this->store->count() === 0) {
            return 'Nothing has been taken out of the context in this session: everything read is still visible above.';
        }

        $budget = $this->budget?->shareInChars(self::SHARE, self::MIN_CHARS, self::MAX_CHARS) ?? self::MIN_CHARS;

        if ($id !== null) {
            $content = $this->store->get($id, $budget);

            if ($content !== null) {
                return $content;
            }

            // Not an error worth throwing: the model guessed an id, and the
            // useful reply is the one that lets it search instead.
            if ($search === null) {
                return "No elided output carries id {$id}. Search by keywords with context_recall(search).";
            }
        }

        if ($search === null || trim($search) === '') {
            return 'Give keywords to look for, or the id quoted in a "[…] elided" message.';
        }

        $found = $this->store->search($search, $budget);

        return $found === ''
            ? "No passage matches \"{$search}\" in what was taken out of the context."
            : $found;
    }
}
