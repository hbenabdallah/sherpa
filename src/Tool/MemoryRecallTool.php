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

        if (empty($facts)) {
            return $search
                ? "Aucun fait trouvé pour: {$search}"
                : "Aucun fait mémorisé pour ce projet.";
        }

        $lines = [];
        foreach ($facts as $fact) {
            $lines[] = "• {$fact->key}: {$fact->value}";
        }

        return implode("\n", $lines);
    }
}
