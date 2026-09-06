<?php

namespace App\TUI;

use App\Project\DockerConfig;
use App\Project\Project;
use App\Project\ProjectStore;

class ProjectSelector
{
    public function __construct(
        private readonly Terminal $terminal,
        private readonly ProjectStore $store,
        private readonly LineEditor $editor,
    ) {}

    /**
     * Show the project selection screen.
     * Returns the selected Project, or null if user quit.
     */
    public function run(): ?Project
    {
        $this->terminal->rawMode();
        $this->terminal->hideCursor();

        try {
            $projects = $this->store->all();
            $selected = 0;

            while (true) {
                $this->render($projects, $selected);
                $key = $this->terminal->readKey();

                // Arrow up
                if ($key === "\e[A") {
                    $selected = max(0, $selected - 1);
                }
                // Arrow down
                elseif ($key === "\e[B") {
                    $max = count($projects); // last item = "new project"
                    $selected = min($max, $selected + 1);
                }
                // Enter
                elseif ($key === "\r" || $key === "\n") {
                    if ($selected === count($projects)) {
                        // "New project" selected
                        $project = $this->createProject();
                        if ($project !== null) {
                            return $project;
                        }
                    } else {
                        return $projects[$selected];
                    }
                }
                // 'n' for new
                elseif ($key === 'n') {
                    $project = $this->createProject();
                    if ($project !== null) {
                        return $project;
                    }
                }
                // 'e' to edit the selected project
                elseif ($key === 'e' && $selected < count($projects)) {
                    if ($this->editProject($projects[$selected]) !== null) {
                        $projects = $this->store->all();
                    }
                }
                // 'd' for delete
                elseif ($key === 'd' && $selected < count($projects)) {
                    // One keypress away from the arrow keys, and it takes the
                    // project's memory with it. Ask.
                    if ($this->confirmDelete($projects[$selected])) {
                        $this->store->delete($projects[$selected]->slug);
                        $projects = $this->store->all();
                        $selected = min($selected, max(0, count($projects) - 1));
                    }
                }
                // 'q' or Ctrl+C to quit
                elseif ($key === 'q' || $key === "\x03") {
                    return null;
                }
            }
        } finally {
            $this->terminal->showCursor();
            $this->terminal->restoreMode();
            // Leave a clean screen with the cursor at the top. Without this the
            // session's first line is appended to the end of the footer, inside
            // the box that is still on screen.
            $this->terminal->clear();
        }
    }

    private function confirmDelete(Project $project): bool
    {
        [, $rows] = $this->terminal->size();

        $this->terminal->moveTo(max(1, $rows - 1), 1);
        echo Terminal::RED . Terminal::BOLD . '  Supprimer « '
            . Terminal::plain($project->name, singleLine: true)
            . ' » et toute sa mémoire ? [o/N] ' . Terminal::RESET;

        // The screen is redrawn from scratch on the next pass, so nothing here
        // needs cleaning up.
        return in_array(strtolower($this->terminal->readKey()), ['o', 'y'], true);
    }

    private function render(array $projects, int $selected): void
    {
        [$cols, $rows] = $this->terminal->size();
        $width = min(60, $cols - 4);
        $x = (int) (($cols - $width) / 2);
        $y = (int) ($rows / 4);

        $this->terminal->clear();

        $itemCount = count($projects) + 1; // +1 for "New project"
        $height = $itemCount + 6;

        $this->terminal->box($x, $y, $width, $height, 'Sherpa · Sélection du projet', Terminal::CYAN);

        foreach ($projects as $i => $project) {
            $isSelected = ($i === $selected);
            $this->renderProjectLine($x + 2, $y + 2 + $i, $width - 4, $project, $isSelected);
        }

        // Separator
        $sepY = $y + 2 + count($projects);
        $this->terminal->moveTo($sepY, $x + 2);
        echo Terminal::GRAY . str_repeat('─', $width - 4) . Terminal::RESET;

        // New project item
        $newSelected = ($selected === count($projects));
        $this->terminal->moveTo($sepY + 1, $x + 2);
        $prefix = $newSelected ? Terminal::BOLD . Terminal::GREEN . '> ' : '  ';
        echo $prefix . Terminal::GREEN . '+ Nouveau projet' . Terminal::RESET;

        // Footer
        $this->terminal->moveTo($y + $height - 1, $x + 2);
        echo Terminal::GRAY . '↑↓ naviguer · ↵ ouvrir · n nouveau · e modifier · d supprimer · q quitter' . Terminal::RESET;
    }

    private function renderProjectLine(int $x, int $y, int $width, Project $project, bool $selected): void
    {
        $this->terminal->moveTo($y, $x);

        $docker = $project->docker->enabled ? ' 🐳' : '';
        $name = mb_substr(Terminal::plain($project->name, singleLine: true), 0, 24);
        $path = mb_substr(Terminal::plain($project->shortPath(), singleLine: true), 0, max(1, $width - 30));

        if ($selected) {
            $nameColor = Terminal::BOLD . Terminal::CYAN;
            $arrow = Terminal::BOLD . Terminal::YELLOW . '> ';
        } else {
            $nameColor = Terminal::WHITE;
            $arrow = '  ';
        }

        $namePad = str_pad($name, 26);
        echo $arrow . $nameColor . $namePad . Terminal::RESET
            . Terminal::GRAY . $path . $docker . Terminal::RESET;
    }

    /**
     * A plain top-down form rather than a box.
     *
     * Answers are typed, and a typed answer longer than the box is wide runs
     * straight through the border and wraps onto the next field's row. Boxes
     * suit fixed content; input does not go in one.
     */
    private function createProject(): ?Project
    {
        return $this->form(null);
    }

    private function editProject(Project $project): ?Project
    {
        return $this->form($project);
    }

    /**
     * A plain top-down form rather than a box, used for both adding and
     * editing.
     *
     * Answers are typed, and a typed answer longer than the box is wide runs
     * straight through the border and wraps onto the next field's row. Boxes
     * suit fixed content; input does not go in one.
     *
     * Nothing is written until the last question is answered, so leaving early
     * changes nothing.
     */
    private function form(?Project $current): ?Project
    {
        $editing = $current !== null;

        $this->terminal->showCursor();
        $this->terminal->restoreMode();
        $this->terminal->clear();

        try {
            echo Terminal::BOLD . Terminal::GREEN
                . ($editing ? "  Modifier « {$current->name} »\n" : "  Nouveau projet\n")
                . Terminal::RESET;
            echo Terminal::GRAY
                . ($editing
                    ? "  Entrée pour conserver la valeur actuelle.\n\n"
                    : "  Entrée vide pour annuler.\n\n")
                . Terminal::RESET;

            $name = $this->askWithDefault('  Nom du projet', $current?->name);
            if ($name === null) {
                return null;
            }

            $home = $this->home();
            echo Terminal::GRAY . "  Chemin relatif à {$home}, ou absolu.\n" . Terminal::RESET;
            $path = $this->askWithDefault('  Chemin', $current?->path);
            if ($path === null) {
                return null;
            }

            $path = $this->expandPath($path, $home);

            if (!is_dir($path)) {
                echo Terminal::RED . "\n  ✗ Répertoire introuvable: {$path}\n" . Terminal::RESET;
                $this->editor->ask('  Entrée pour revenir… ', Terminal::GRAY);

                return null;
            }

            echo Terminal::GRAY . "  → {$path}\n\n" . Terminal::RESET;

            $dockerConfig = $this->askDocker($current?->docker);

            if ($editing) {
                // Only here does anything get written, so abandoning the form
                // above leaves the project exactly as it was.
                echo "\n";
                $confirm = strtolower($this->editor->ask('  Enregistrer ces modifications? [o/N]: ', Terminal::BOLD));

                if (!in_array($confirm, ['o', 'oui', 'y', 'yes'], true)) {
                    echo Terminal::GRAY . "  Rien n'a été modifié.\n" . Terminal::RESET;
                    $this->editor->ask('  Entrée pour revenir… ', Terminal::GRAY);

                    return null;
                }

                return $this->store->update($current, $name, $path, $dockerConfig);
            }

            return $this->store->create($name, $path, $dockerConfig);
        } finally {
            $this->terminal->rawMode();
            $this->terminal->hideCursor();
        }
    }

    private function askDocker(?DockerConfig $current): DockerConfig
    {
        $default = $current?->enabled === true ? 'O/n' : 'o/N';
        $answer = strtolower($this->editor->ask("  Environnement Docker? [{$default}]: ", Terminal::BOLD));

        $enabled = $answer === ''
            ? ($current?->enabled ?? false)
            : in_array($answer, ['o', 'oui', 'y', 'yes'], true);

        if (!$enabled) {
            return new DockerConfig(enabled: false);
        }

        return new DockerConfig(
            enabled: true,
            container: $this->askWithDefault('  Conteneur app', $current?->container) ?? '',
            dbContainer: $this->askWithDefault('  Conteneur DB (optionnel)', $current?->dbContainer, allowEmpty: true) ?? '',
        );
    }

    /**
     * @return string|null null means "give up on this form": an empty answer
     *                     where there is no current value to fall back on
     */
    private function askWithDefault(string $label, ?string $current, bool $allowEmpty = false): ?string
    {
        $shown = $current !== null && $current !== ''
            ? $label . ' [' . mb_substr($current, 0, 48) . ']: '
            : $label . ': ';

        $answer = $this->editor->ask($shown, Terminal::BOLD);

        if ($answer !== '') {
            return $answer;
        }

        if ($current !== null && $current !== '') {
            return $current;
        }

        return $allowEmpty ? '' : null;
    }

    private function home(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');

        return is_string($home) && $home !== '' ? $home : '/';
    }

    private function expandPath(string $path, string $home): string
    {
        if ($path === '~') {
            return $home;
        }
        if (str_starts_with($path, '~/')) {
            return $home . substr($path, 1);
        }
        if (!str_starts_with($path, '/')) {
            return $home . '/' . $path;
        }

        return $path;
    }
}
