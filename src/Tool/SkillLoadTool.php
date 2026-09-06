<?php

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Skills\SkillRegistry;

#[AsTool(
    name: 'skill_load',
    description: 'Load the full content of a skill document into context. Use when you need detailed guidance on a specific technical domain.',
    permission: Permission::AUTO,
)]
class SkillLoadTool
{
    public function __construct(private readonly SkillRegistry $registry) {}

    public function __invoke(
        #[Param('Name of the skill to load (as shown in the skills index)')] string $name,
    ): string {
        $skill = $this->registry->get($name);

        if ($skill === null) {
            $available = implode(', ', array_map(fn($s) => $s->name, $this->registry->all()));
            return "Skill '{$name}' not found. Available: {$available}";
        }

        return $skill->content;
    }
}
