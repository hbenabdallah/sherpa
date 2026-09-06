<?php

namespace App\Agent;

use App\Memory\MemoryStore;
use App\Project\Project;
use App\Project\StackDetector;
use App\Skills\SkillRegistry;

class SystemPromptBuilder
{
    /**
     * Share of the prompt allowance spent quoting remembered facts.
     *
     * This used to be a flat 1600 characters, described as "roughly 450 tokens
     * of a 28k allowance" — which is exactly the problem: the number was a
     * percentage written down as a constant, and it stopped being that
     * percentage the moment the window changed. A model addressing 256k got the
     * same dozen facts as one addressing 32k, rationing itself to a fraction of
     * a percent of what it could comfortably carry, and sending the model to
     * memory_recall for things that would have fitted in the prompt all along.
     */
    private const MEMORY_BUDGET_SHARE = 0.015;

    /**
     * The floor keeps small windows behaving exactly as before. The ceiling
     * exists because this block is speculative: it is built before anyone has
     * asked anything, so past a point it is spending context on facts nobody
     * needed — and memory_recall is the targeted lookup that replaces it.
     */
    private const MEMORY_BUDGET_MIN_CHARS = 1600;
    private const MEMORY_BUDGET_MAX_CHARS = 8000;

    public function __construct(
        private readonly MemoryStore $memory,
        private readonly SkillRegistry $skills,
        private readonly StackDetector $detector,
        // Optional so the builder stays constructible on its own — stack_test
        // builds one with no budget at all. Absent, the floor applies, which is
        // the behaviour this class had before the window became a variable.
        private readonly ?ContextBudget $budget = null,
    ) {}

    /**
     * How many characters of remembered fact this model can afford.
     *
     * Computed rather than configured: the right answer is a share of the
     * window, and the window is now chosen per machine. Converting tokens to
     * characters uses whatever ratio ContextBudget has learned — 3.4 on a cold
     * start, and the prompt is rebuilt on /reset and on every model change, so
     * a calibrated ratio does get used.
     */
    private function memoryBudget(): int
    {
        if ($this->budget === null) {
            return self::MEMORY_BUDGET_MIN_CHARS;
        }

        $chars = (int) ($this->budget->promptLimit()
            * self::MEMORY_BUDGET_SHARE
            * $this->budget->charsPerToken());

        return max(self::MEMORY_BUDGET_MIN_CHARS, min(self::MEMORY_BUDGET_MAX_CHARS, $chars));
    }

    public function build(Project $project): string
    {
        $docker = $project->docker->enabled
            ? "oui, conteneur " . $project->docker->container
            : "non";

        // Detected here rather than read off the project: the stored value was
        // written when the project was added and the prompt is the one place
        // where being a version behind actually misleads the model.
        $stack = $this->detector->detect($project->path);
        $identity = $stack->language === null
            ? 'un agent de développement qui s\'exécute dans le terminal de l\'utilisateur'
            : "un agent de développement {$stack->language} qui s'exécute dans le terminal de l'utilisateur";

        // Budgeted in characters rather than in facts: the block has to have a
        // predictable cost, and what does not fit has to be named rather than
        // dropped in silence.
        $memorySummary = $this->memory->digest($this->memoryBudget());
        $skillsIndex = $this->skills->getIndex();

        return <<<PROMPT
# Identité
Tu es Sherpa, {$identity}.
Tu es expert, direct, et précis. Tu utilises les tools avant de coder à l'aveugle.
Tu écris dans le langage et avec les conventions du projet ci-dessous, jamais dans un autre.

# Projet courant
Nom : {$project->name}
Chemin : {$project->path}
Docker : {$docker}
Stack détectée : {$stack->summary()}

# Mémoire permanente (faits durables sur ce projet)
{$memorySummary}

Cette liste peut être partielle. Utilise memory_recall(search) pour retrouver un
fait dont seule la clé est citée ci-dessus, ou pour chercher par mots-clés.

# Skills disponibles (chargement à la demande)
{$skillsIndex}

Charge un skill avec skill_load(name) quand son domaine est pertinent pour la demande.

# Règles
- Les tools ne peuvent accéder qu'aux fichiers situés dans le projet ci-dessus.
  Tout chemin en dehors est refusé : n'essaie pas de lire /etc, ~/.ssh, ni de
  remonter avec "../". Utilise des chemins relatifs à la racine du projet.
- Utilise toujours les tools pour lire le code avant de proposer des modifications.
- Commence par project_grep ou file_read avant d'écrire quoi que ce soit.
- Pour file_write et file_patch, explique brièvement ce que tu vas faire avant l'appel.
- Si tu découvres un fait important sur le projet (convention, pattern, décision archi), mémorise-le avec memory_remember.
- Réponds toujours en français.
- Sois concis dans les explications, précis dans le code.
PROMPT;
    }
}
