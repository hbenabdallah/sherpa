<?php

namespace App\Agent;

use App\Memory\MemoryStore;
use App\Project\Project;
use App\Project\RecentActivity;
use App\Project\StackDetector;
use App\Project\TestCommandDetector;
use App\Skills\SkillRegistry;

class SystemPromptBuilder
{
    /**
     * Share of the prompt allowance spent quoting remembered facts. A flat
     * 1600 characters was a percentage written down as a constant: a model
     * addressing 256k got the same dozen facts as one addressing 32k.
     */
    private const MEMORY_BUDGET_SHARE = 0.015;

    /**
     * The floor keeps small windows as they were. The ceiling is there because
     * this block is speculative — built before anyone asked anything — and past
     * a point it spends context on facts nobody needed; memory_recall is the
     * targeted lookup that replaces it.
     */
    private const MEMORY_BUDGET_MIN_CHARS = 1600;
    private const MEMORY_BUDGET_MAX_CHARS = 24000;

    public function __construct(
        private readonly MemoryStore $memory,
        private readonly SkillRegistry $skills,
        private readonly StackDetector $detector,
        // Optional so the builder stays constructible on its own — stack_test
        // builds one with no budget at all. Absent, the floor applies, which is
        // the behaviour this class had before the window became a variable.
        private readonly ?ContextBudget $budget = null,
        // Optional for the same reason as the budget: stack_test builds a
        // builder on its own. Absent, the digest ranks on what it always did.
        private readonly ?RecentActivity $activity = null,
        // Optional for the same reason. Absent, the prompt says the command is
        // unknown and where to look for it.
        private readonly ?TestCommandDetector $tests = null,
    ) {}

    /**
     * The line that tells the model how this project checks itself.
     *
     * Named even when unknown: the failure it prevents is a model taking the
     * silence of a command that tested nothing for a pass.
     */
    private function testsLine(Project $project): string
    {
        $command = $this->tests?->detect($project->path);

        return $command === null
            ? 'unknown — find it (README, composer.json, Makefile) before concluding a change works'
            : "`{$command->command}` (from {$command->source})";
    }

    /**
     * What this session appears to be about, for ranking the memory block:
     * SHERPA_CWD, the launch directory, passed into the container because
     * nothing inside it can know. The cheapest signal there is.
     *
     * @return string[]
     */
    private function situationTerms(Project $project): array
    {
        if ($this->activity === null) {
            return [];
        }

        $launchDir = $_SERVER['SHERPA_CWD'] ?? getenv('SHERPA_CWD') ?: null;

        return $this->activity->terms($project->path, is_string($launchDir) && $launchDir !== '' ? $launchDir : null);
    }

    /**
     * How many characters of remembered fact this model can afford: a share of
     * the window, which is chosen per machine, converted with whatever ratio
     * ContextBudget has learned — 3.4 cold, and the prompt is rebuilt on
     * /reset and on every model change, so a calibrated one does get used.
     */
    private function memoryBudget(): int
    {
        if ($this->budget === null) {
            return self::MEMORY_BUDGET_MIN_CHARS;
        }

        return $this->budget->shareInChars(
            self::MEMORY_BUDGET_SHARE,
            self::MEMORY_BUDGET_MIN_CHARS,
            self::MEMORY_BUDGET_MAX_CHARS,
        );
    }

    public function build(Project $project): string
    {
        $docker = $project->docker->enabled
            ? 'yes, container ' . $project->docker->container
            : 'no';

        // Detected here rather than read off the project: the stored value was
        // written when the project was added and the prompt is the one place
        // where being a version behind actually misleads the model.
        $stack = $this->detector->detect($project->path);
        $identity = $stack->language === null
            ? 'a development agent running in the user\'s terminal'
            : "a {$stack->language} development agent running in the user's terminal";

        // Budgeted in characters rather than in facts: the block has to have a
        // predictable cost, and what does not fit has to be named rather than
        // dropped in silence.
        $memorySummary = $this->memory->digest($this->memoryBudget(), $this->situationTerms($project));
        $skillsIndex = $this->skills->getIndex();
        $testsLine = $this->testsLine($project);

        return <<<PROMPT
# Identity
You are Sherpa, {$identity}.
You are expert, direct and precise. You use the tools before coding blind.
You write in the language and the conventions of the project below, never another.

# Current project
Name: {$project->name}
Path: {$project->path}
Docker: {$docker}
Stack detected: {$stack->summary()}
Test command: {$testsLine}

# Standing memory (lasting facts about this project)
{$memorySummary}

This list may be partial. Use memory_recall(search) to retrieve a fact whose key
alone is quoted above, or to search by keywords.

# Skills available (loaded on demand)
{$skillsIndex}

Load one with skill_load(name) when its subject is what the request is about.

# Rules
- The tools reach the files of the project above and nothing else. Any path
  outside it is refused: do not try to read /etc or ~/.ssh, nor to climb out
  with "../". Use paths relative to the project root.
- Always read the code with the tools before proposing changes.
- For a rule, a procedure or a decision of the project, search its documentation
  first with doc_search, and cite your sources (path › section). If it does not
  answer, say so rather than assume.
- To find code, start with project_grep. When you do not know where to look,
  list_dir shows how the project is laid out: do not guess file names.
- Read with file_read before writing anything.
- For file_write and file_patch, say briefly what you are about to do first.
- After changing code, run the project's test command with shell_exec. A command
  that prints nothing has checked nothing.
- When you learn something lasting about the project (a convention, a pattern, an
  architectural decision), record it with memory_remember.
- Reply in the language the user writes in.
- Be concise in explanations, precise in code.
PROMPT;
    }
}
