<?php

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Memory\MemoryStore;
use App\Memory\Recorded;

#[AsTool(
    name: 'memory_remember',
    description: 'Persist a durable fact about the project for future sessions. Use for architectural decisions, conventions, key file locations, etc. Set replaces=true only to correct something already known under the same key.',
    permission: Permission::AUTO,
)]
class MemoryRememberTool
{
    public function __construct(private readonly MemoryStore $store) {}

    public function __invoke(
        #[Param('Short key identifying the fact (e.g. "auth_strategy", "db_naming_convention")')] string $key,
        #[Param('Value of the fact — be concise and precise')] string $value,
        #[Param('True only when this corrects what is already known under this key; otherwise the new fact is kept alongside the existing ones')] bool $replaces = false,
    ): string {
        $recorded = $replaces
            ? $this->store->supersede($key, $value)
            : $this->store->remember($key, $value);

        // Told what actually happened, the model stops re-recording the same
        // fact every turn and can see when it has just corrected itself.
        return match ($recorded) {
            Recorded::Created    => "Mémorisé: {$key} = {$value}",
            Recorded::Unchanged  => "Déjà mémorisé, rien à changer: {$key} = {$value}",
            Recorded::Superseded => "Corrigé: {$key} = {$value} (la valeur précédente reste dans l'historique)",
        };
    }
}
