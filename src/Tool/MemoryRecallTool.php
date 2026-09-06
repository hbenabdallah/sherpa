<?php

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Memory\MemoryStore;

#[AsTool(
    name: 'memory_recall',
    description: 'Retrieve facts previously memorized about this project. The search matches whole words in any order and returns the closest matches first.',
    permission: Permission::AUTO,
)]
class MemoryRecallTool
{
    public function __construct(private readonly MemoryStore $store) {}

    public function __invoke(
        #[Param('Optional words to search for, in any order (leave empty for all recent facts)')] ?string $search = null,
    ): string {
        $facts = $this->store->recall($search, limit: 20);

        // Counted here rather than in the store, and only for a real search:
        // digest() also goes through recall() to build the prompt, and counting
        // that would mark every fact as used in every session.
        if ($search !== null && trim($search) !== '') {
            $this->store->noteRecall($facts !== []);
        }

        if (empty($facts)) {
            return $search
                ? "No fact found for: {$search}"
                : "Nothing remembered for this project.";
        }

        // A fact the model went looking for has shown it belongs in the prompt;
        // digest() ranks on this next session.
        $this->store->markRecalled(array_map(fn($fact) => $fact->id, $facts));

        $lines = [];
        foreach ($facts as $fact) {
            $lines[] = "• {$fact->key}: {$fact->value}";
        }

        return implode("\n", $lines);
    }
}
