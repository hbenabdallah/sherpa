<?php

declare(strict_types=1);

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Skills\Skill;
use App\Skills\SkillRegistry;

#[AsTool(
    name: 'skill_load',
    description: 'Load the full content of a skill document into context. Use when you need detailed guidance on a specific technical domain.',
    permission: Permission::AUTO,
)]
final readonly class SkillLoadTool
{
    public function __construct(private SkillRegistry $registry) {}

    public function __invoke(
        #[Param('Name of the skill to load (as shown in the skills index)')]
        string $name,
    ): string {
        $skill = $this->registry->get($name);

        if (!$skill instanceof Skill) {
            $available = implode(', ', array_map(fn(Skill $s): string => $s->name, $this->registry->all()));

            return "Skill '{$name}' not found. Available: {$available}";
        }

        return $skill->content;
    }
}
