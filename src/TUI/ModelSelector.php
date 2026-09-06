<?php

declare(strict_types=1);

namespace App\TUI;

use App\Config\ModelProfile;
use App\Platform\ModelInfo;
use App\Platform\PlatformInterface;

/**
 * « Quel modèle ? » — the question asked the first time Sherpa runs on a
 * machine, and again whenever /model is typed.
 *
 * The list is what the backend actually holds, so it is right on any machine
 * without anything being written down in advance. But the list is not the
 * limit: a name can always be typed, including one that has never been pulled.
 * Hard-coding a menu of known-good models would have made Sherpa work on the
 * machine it was written on and nowhere else.
 */
class ModelSelector
{
    /** Above this, a window is very unlikely to fit beside the weights. */
    private const LARGE_WINDOW = 131072;

    public function __construct(
        private readonly Terminal $terminal,
        private readonly LineEditor $editor,
        private readonly PlatformInterface $platform,
    ) {}

    /**
     * @param bool $firstRun changes the framing only: the same screen either
     *                       welcomes someone or lets them change their mind
     *
     * @return ModelProfile|null null when the user backed out and whatever was
     *                           already in force should stay in force
     */
    public function run(?ModelProfile $current = null, bool $firstRun = false): ?ModelProfile
    {
        $models = $this->platform->catalogue();

        $this->terminal->rawMode();
        $this->terminal->hideCursor();

        try {
            $selected = $this->indexOf($models, $current?->model);

            while (true) {
                $this->render($models, $selected, $current, $firstRun);

                $key = $this->terminal->readKey();
                $last = count($models); // the "type a name" row sits after the list

                if ($key === "\e[A") {
                    $selected = max(0, $selected - 1);
                } elseif ($key === "\e[B") {
                    $selected = min($last, $selected + 1);
                } elseif ($key === 'a' || $key === 'A') {
                    $profile = $this->askForName($current);
                    if ($profile !== null) {
                        return $profile;
                    }
                } elseif ($key === "\r" || $key === "\n") {
                    $profile = $selected === $last
                        ? $this->askForName($current)
                        : $this->accept($models[$selected], $current);

                    if ($profile !== null) {
                        return $profile;
                    }
                } elseif ($key === 'q' || $key === "\x03") {
                    return null;
                }
            }
        } finally {
            $this->terminal->showCursor();
            $this->terminal->restoreMode();
            $this->terminal->clear();
        }
    }

    /**
     * A model named rather than chosen.
     *
     * The backend is asked about it, which settles the window and the tool
     * support. A name it does not know is not refused — it is reported, with
     * the command that would fix it, and kept if that is what was meant. Ollama
     * pulls on first use, so a name typed here can legitimately be one that
     * does not exist yet.
     */
    private function askForName(?ModelProfile $current): ?ModelProfile
    {
        $this->terminal->showCursor();
        $this->terminal->restoreMode();
        $this->terminal->clear();

        try {
            echo Terminal::BOLD . Terminal::GREEN . "\n  Choisir un modèle par son nom\n" . Terminal::RESET;
            echo Terminal::GRAY . "  Tel qu'Ollama le nomme, étiquette comprise — par exemple "
                . "llama3.1:8b.\n  Entrée vide pour revenir à la liste.\n\n" . Terminal::RESET;

            $name = trim($this->editor->ask('  Modèle: ', Terminal::BOLD));
            if ($name === '') {
                return null;
            }

            echo Terminal::GRAY . "\n  Interrogation du serveur…\n" . Terminal::RESET;
            $info = $this->platform->describeModel($name);

            if ($info === null) {
                echo Terminal::YELLOW . "\n  ⚠ Ce serveur ne connaît pas « {$name} ».\n" . Terminal::RESET;
                echo Terminal::GRAY . "    Récupérez-le d'abord :  ollama pull {$name}\n"
                    . "    Sa fenêtre native reste inconnue jusque-là.\n\n" . Terminal::RESET;

                $keep = strtolower($this->editor->ask('  Le retenir quand même? [o/N]: ', Terminal::BOLD));
                if (!in_array($keep, ['o', 'oui', 'y', 'yes'], true)) {
                    return null;
                }

                $info = new ModelInfo(name: $name);
            }

            return $this->confirm($info, $current);
        } finally {
            $this->terminal->rawMode();
            $this->terminal->hideCursor();
        }
    }

    /** A model picked off the list. */
    private function accept(ModelInfo $info, ?ModelProfile $current): ?ModelProfile
    {
        $this->terminal->showCursor();
        $this->terminal->restoreMode();
        $this->terminal->clear();

        try {
            // The list may have come from /api/tags without the two fields that
            // matter. Ask about this one model rather than about all of them.
            if ($info->contextLength === null || $info->capabilitiesUnknown()) {
                echo Terminal::GRAY . "\n  Interrogation du serveur…\n" . Terminal::RESET;
                $info = $this->platform->describeModel($info->name) ?? $info;
            }

            return $this->confirm($info, $current);
        } finally {
            $this->terminal->rawMode();
            $this->terminal->hideCursor();
        }
    }

    /**
     * Check the model can do the job, then settle its window.
     *
     * Assumes the terminal is already out of raw mode with a visible cursor —
     * both callers arrange that, because both need to read a typed line.
     */
    private function confirm(ModelInfo $info, ?ModelProfile $current): ?ModelProfile
    {
        if (!$info->supportsTools()) {
            echo Terminal::RED . Terminal::BOLD . "\n  ✗ {$info->name} ne sait pas appeler d'outils.\n" . Terminal::RESET;
            echo Terminal::GRAY . "    Sherpa est une boucle d'appels d'outils : ce modèle ne pourrait\n"
                . "    ni lire un fichier ni lancer une commande. Il parlerait, sans agir.\n\n" . Terminal::RESET;
            $this->editor->ask('  Entrée pour choisir autrement… ', Terminal::GRAY);

            return null;
        }

        $native = $info->contextLength;
        $suggested = ModelProfile::suggestFor($info)->contextWindow;

        // Keeping the window when only retuning the same model: someone who
        // already tuned this to their card should not have to redo it.
        if ($current !== null && $current->model === $info->name) {
            $suggested = $native === null ? $current->contextWindow : min($current->contextWindow, $native);
        }

        echo Terminal::BOLD . Terminal::GREEN . "\n  {$info->name}\n" . Terminal::RESET;

        $facts = array_filter([
            $info->parameterSize,
            $info->humanSize(),
            $info->capabilitiesUnknown() ? null : implode(' · ', $info->capabilities),
        ], fn(?string $f) => $f !== null && $f !== '');

        if ($facts !== []) {
            echo Terminal::GRAY . '  ' . implode('  ·  ', $facts) . "\n" . Terminal::RESET;
        }

        echo "\n";

        if ($native !== null) {
            echo Terminal::GRAY . "  Fenêtre native : " . Terminal::RESET
                . number_format($native, 0, ',', ' ') . " tokens (" . $info->humanContext() . ")\n";
        } else {
            echo Terminal::GRAY . "  Fenêtre native inconnue — le serveur n'a rien annoncé.\n" . Terminal::RESET;
        }

        echo Terminal::GRAY
            . "\n  Ce chiffre est un plafond, pas un réglage. La fenêtre demandée occupe\n"
            . "  de la VRAM en plus des poids du modèle ; quand il n'y en a plus, Ollama\n"
            . "  bascule sur le processeur sans le dire et le GPU cesse de servir.\n"
            . "  Vérifiez avec « ollama ps » : tant qu'il affiche 100% GPU, c'est tenable.\n\n"
            . Terminal::RESET;

        $answer = trim($this->editor->ask(
            "  Fenêtre de contexte [{$suggested}]: ",
            Terminal::BOLD,
        ));

        $window = $answer === '' ? $suggested : (int) $answer;

        if ($window <= 0) {
            echo Terminal::YELLOW . "  Valeur inutilisable, {$suggested} retenu.\n" . Terminal::RESET;
            $window = $suggested;
        }

        if ($native !== null && $window > $native) {
            echo Terminal::YELLOW . "  ⚠ Au-delà du plafond du modèle ; ramené à {$native}.\n" . Terminal::RESET;
            $window = $native;
        }

        if ($window > self::LARGE_WINDOW) {
            echo Terminal::YELLOW . "  ⚠ Fenêtre très large : surveillez « ollama ps » au premier tour.\n" . Terminal::RESET;
        }

        return new ModelProfile($info->name, $window, 'choisi ici');
    }

    private function render(array $models, int $selected, ?ModelProfile $current, bool $firstRun): void
    {
        [$cols, $rows] = $this->terminal->size();
        $width = min(78, max(48, $cols - 4));
        $x = (int) (($cols - $width) / 2);
        $y = max(2, (int) ($rows / 6));

        $this->terminal->clear();

        $height = count($models) + 8;
        $title = $firstRun ? 'Sherpa · Quel modèle ?' : 'Sherpa · Changer de modèle';
        $this->terminal->box($x, $y, $width, $height, $title, Terminal::CYAN);

        $this->terminal->moveTo($y + 1, $x + 2);
        echo Terminal::GRAY . mb_substr($this->intro($models, $current, $firstRun), 0, $width - 4) . Terminal::RESET;

        foreach ($models as $i => $model) {
            $this->renderLine($x + 2, $y + 3 + $i, $width - 4, $model, $i === $selected, $current);
        }

        $sepY = $y + 3 + count($models);
        $this->terminal->moveTo($sepY, $x + 2);
        echo Terminal::GRAY . str_repeat('─', $width - 4) . Terminal::RESET;

        $otherSelected = $selected === count($models);
        $this->terminal->moveTo($sepY + 1, $x + 2);
        echo ($otherSelected ? Terminal::BOLD . Terminal::GREEN . '> ' : '  ')
            . Terminal::GREEN . '+ Un autre modèle (saisir un nom)' . Terminal::RESET;

        $this->terminal->moveTo($y + $height - 1, $x + 2);
        echo Terminal::GRAY
            . mb_substr('↑↓ naviguer · ↵ choisir · a saisir un nom · q ' . ($firstRun ? 'quitter' : 'annuler'), 0, $width - 4)
            . Terminal::RESET;
    }

    private function intro(array $models, ?ModelProfile $current, bool $firstRun): string
    {
        if ($models === []) {
            return 'Aucun modèle listé — le serveur est-il joignable ? Saisissez un nom avec « a ».';
        }

        $count = count($models) . ' modèle' . (count($models) > 1 ? 's' : '') . ' sur ' . $this->platform->name();

        return $firstRun
            ? $count . ' — ce choix sera retenu pour cette machine.'
            : $count . ($current === null ? '' : ' — actuel : ' . $current->model);
    }

    private function renderLine(int $x, int $y, int $width, ModelInfo $model, bool $selected, ?ModelProfile $current): void
    {
        $this->terminal->moveTo($y, $x);

        $name = mb_substr(Terminal::plain($model->name, singleLine: true), 0, 30);
        $isCurrent = $current !== null && $current->model === $model->name;

        $meta = trim(sprintf(
            '%8s %8s %6s',
            $model->parameterSize ?? '',
            $model->humanSize(),
            $model->humanContext(),
        ));

        // Named, not merely coloured: a model that cannot call tools is the one
        // thing on this screen that would waste an entire session.
        if (!$model->supportsTools()) {
            $meta .= '  sans tools';
        }

        $arrow = $selected ? Terminal::BOLD . Terminal::YELLOW . '> ' : '  ';
        $nameColour = match (true) {
            !$model->supportsTools() => Terminal::RED,
            $selected               => Terminal::BOLD . Terminal::CYAN,
            default                 => Terminal::WHITE,
        };

        echo $arrow . $nameColour . str_pad($name, 32) . Terminal::RESET
            . Terminal::GRAY . mb_substr($meta, 0, max(1, $width - 34)) . Terminal::RESET
            . ($isCurrent ? Terminal::CYAN . ' ●' . Terminal::RESET : '');
    }

    /** @param array<int, ModelInfo> $models */
    private function indexOf(array $models, ?string $name): int
    {
        if ($name === null) {
            return 0;
        }

        foreach ($models as $i => $model) {
            if ($model->name === $name) {
                return $i;
            }
        }

        return 0;
    }
}
