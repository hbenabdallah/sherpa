<?php

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Agent\Tool\Provenance;
use App\Memory\MemoryStore;
use App\Memory\Recorded;

#[AsTool(
    name: 'memory_remember',
    description: 'Persist a durable fact about the project for future sessions. Use for architectural decisions, conventions, key file locations, etc. Set replaces=true only to correct something already known under the same key.',
    permission: Permission::AUTO,
)]
class MemoryRememberTool
{
    public function __construct(
        private readonly MemoryStore $store,
        // What the session read from outside by now, kept with the fact.
        private readonly ?Provenance $provenance = null,
    ) {}

    public function __invoke(
        #[Param('Short key identifying the fact (e.g. "auth_strategy", "db_naming_convention")')] string $key,
        #[Param('Value of the fact — be concise and precise')] string $value,
        #[Param('True only when this corrects what is already known under this key; otherwise the new fact is kept alongside the existing ones')] bool $replaces = false,
    ): string {
        $source = Provenance::sourceOf('the model', $this->provenance);
        $recorded = $replaces
            ? $this->store->supersede($key, $value, $source)
            : $this->store->remember($key, $value, $source);

        // Told what actually happened, the model stops re-recording the same
        // fact every turn and can see when it has just corrected itself.
        return match ($recorded) {
            Recorded::Created    => "Remembered: {$key} = {$value}",
            Recorded::Unchanged  => "Already remembered, nothing to change: {$key} = {$value}",
            Recorded::Superseded => "Corrected: {$key} = {$value} (the previous value stays on record)",
        };
    }
}
