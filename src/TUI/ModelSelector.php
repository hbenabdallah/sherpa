<?php

declare(strict_types=1);

namespace App\TUI;

use App\Config\Backend;
use App\Config\ModelProfile;
use App\Platform\ModelInfo;
use App\Platform\PlatformInterface;

/**
 * "Which model?" — the question asked the first time Sherpa runs on a
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

    /** Rows skipped by PageUp / PageDown — a provider can list hundreds. */
    private const PAGE = 10;

    /** Whose models are on screen. Set by run(), read by every step after it. */
    private Backend $backend = Backend::DEFAULT;

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
    public function run(?ModelProfile $current = null, bool $firstRun = false, ?Backend $backend = null): ?ModelProfile
    {
        $this->backend = $backend ?? $current?->backend ?? Backend::DEFAULT;

        // A model of the other backend is not "the current one" on this list,
        // even when the names happen to match.
        if ($current !== null && $current->backend !== $this->backend) {
            $current = null;
        }

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
                } elseif ($key === "\e[5~") {
                    $selected = max(0, $selected - self::PAGE);
                } elseif ($key === "\e[6~") {
                    $selected = min($last, $selected + self::PAGE);
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
            echo Terminal::BOLD . Terminal::GREEN . "\n  Choose a model by name\n" . Terminal::RESET;
            echo Terminal::GRAY . ($this->backend->isLocal()
                    ? "  As Ollama names it, tag included — llama3.1:8b, say.\n"
                    : "  As the provider names it, the exact id from its documentation.\n")
                . "  Empty line to go back to the list.\n\n" . Terminal::RESET;

            $name = trim($this->editor->ask('  Model: ', Terminal::BOLD));
            if ($name === '') {
                return null;
            }

            echo Terminal::GRAY . "\n  Asking the server…\n" . Terminal::RESET;
            $info = $this->platform->describeModel($name);

            if ($info === null) {
                echo Terminal::YELLOW . "\n  ⚠ This server does not know \"{$name}\".\n" . Terminal::RESET;
                echo Terminal::GRAY . ($this->backend->isLocal()
                        ? "    Pull it first:  ollama pull {$name}\n"
                            . "    Its native window stays unknown until then.\n\n"
                        : "    Some providers accept names that are not on their list;\n"
                            . "    otherwise the first request will say so.\n\n")
                    . Terminal::RESET;

                $keep = strtolower($this->editor->ask('  Keep it anyway? [y/N]: ', Terminal::BOLD));
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
                echo Terminal::GRAY . "\n  Asking the server…\n" . Terminal::RESET;
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
            echo Terminal::RED . Terminal::BOLD . "\n  ✗ {$info->name} cannot call tools.\n" . Terminal::RESET;
            echo Terminal::GRAY . "    Sherpa is a loop of tool calls: this model could neither\n"
                . "    read a file nor run a command. It would talk, without acting.\n\n" . Terminal::RESET;
            $this->editor->ask('  Enter to choose another one… ', Terminal::GRAY);

            return null;
        }

        $native = $info->contextLength;
        $suggested = ModelProfile::suggestFor($info)->contextWindow;

        // Keeping the window when only retuning the same model: someone who
        // already tuned this to their card should not have to redo it.
        if ($current !== null && $current->backend === $this->backend && $current->model === $info->name) {
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
            echo Terminal::GRAY . "  Native window: " . Terminal::RESET
                . number_format($native, 0, '.', ',') . " tokens (" . $info->humanContext() . ")\n";
        } else {
            echo Terminal::GRAY . "  Native window unknown — the server announced none.\n" . Terminal::RESET;
        }

        echo Terminal::GRAY . ($this->backend->isLocal()
                ? "\n  That number is a ceiling, not a setting. The window asked for takes\n"
                    . "  VRAM on top of the model's weights; when there is none left, Ollama\n"
                    . "  moves onto the processor without saying so and the GPU stops serving.\n"
                    . "  Check with \"ollama ps\": as long as it shows 100% GPU, it holds.\n\n"
                : "\n  That number is a ceiling, not a setting. The window is Sherpa's budget:\n"
                    . "  every turn sends the whole conversation back, and a wider window\n"
                    . "  compacts later — so long sessions cost more.\n\n")
            . Terminal::RESET;

        $answer = trim($this->editor->ask(
            "  Context window [{$suggested}]: ",
            Terminal::BOLD,
        ));

        $window = $answer === '' ? $suggested : (int) $answer;

        if ($window <= 0) {
            echo Terminal::YELLOW . "  Unusable value, keeping {$suggested}.\n" . Terminal::RESET;
            $window = $suggested;
        }

        if ($native !== null && $window > $native) {
            echo Terminal::YELLOW . "  ⚠ Past the model's ceiling; brought down to {$native}.\n" . Terminal::RESET;
            $window = $native;
        }

        if ($window > self::LARGE_WINDOW && $this->backend->isLocal()) {
            echo Terminal::YELLOW . "  ⚠ Very wide window: watch \"ollama ps\" on the first turn.\n" . Terminal::RESET;
        }

        return new ModelProfile($info->name, $window, 'choisi ici', $this->backend);
    }

    private function render(array $models, int $selected, ?ModelProfile $current, bool $firstRun): void
    {
        [$cols, $rows] = $this->terminal->size();
        $width = min(78, max(48, $cols - 4));
        $x = (int) (($cols - $width) / 2);
        $y = max(2, (int) ($rows / 6));

        $this->terminal->clear();

        // Only as many rows as the terminal holds. A local server lists the
        // handful of models it has pulled; a provider lists every model it
        // sells, and a box taller than the screen scrolls its own title and
        // footer out of sight.
        $count = count($models);
        $visible = min($count, max(3, $rows - $y - 9));
        $offset = $count <= $visible ? 0 : max(0, min($selected - intdiv($visible, 2), $count - $visible));

        $height = $visible + 8;
        $title = $firstRun ? 'Sherpa · Which model?' : 'Sherpa · Change model';
        $this->terminal->box($x, $y, $width, $height, $title, Terminal::CYAN);

        $this->terminal->moveTo($y + 1, $x + 2);
        echo Terminal::GRAY . mb_substr($this->intro($models, $current, $firstRun), 0, $width - 4) . Terminal::RESET;

        for ($row = 0; $row < $visible; $row++) {
            $i = $offset + $row;
            $this->renderLine($x + 2, $y + 3 + $row, $width - 4, $models[$i], $i === $selected, $current);
        }

        $sepY = $y + 3 + $visible;
        $this->terminal->moveTo($sepY, $x + 2);
        $position = $count > $visible ? ' ' . min($selected + 1, $count) . '/' . $count . ' ' : '';
        echo Terminal::GRAY . str_repeat('─', max(0, $width - 4 - mb_strlen($position))) . $position . Terminal::RESET;

        $otherSelected = $selected === count($models);
        $this->terminal->moveTo($sepY + 1, $x + 2);
        echo ($otherSelected ? Terminal::BOLD . Terminal::GREEN . '> ' : '  ')
            . Terminal::GREEN . '+ Another model (type a name)' . Terminal::RESET;

        $this->terminal->moveTo($y + $height - 1, $x + 2);
        echo Terminal::GRAY
            . mb_substr('↑↓ move · ↵ choose · a type a name · q ' . ($firstRun ? 'quit' : 'cancel')
                . ($count > $visible ? ' · PgUp/PgDn' : ''), 0, $width - 4)
            . Terminal::RESET;
    }

    private function intro(array $models, ?ModelProfile $current, bool $firstRun): string
    {
        if ($models === []) {
            // Reached only once the server has answered: it simply publishes
            // no list, as Cloudflare does not.
            return 'This server publishes no list of models — type a name with "a".';
        }

        $count = count($models) . ' model' . (count($models) > 1 ? 's' : '') . ' on ' . $this->platform->name()
            . ($this->backend->isLocal() ? ' (local)' : ' (online)');

        return $firstRun
            ? $count . ' — this choice is kept for this machine.'
            : $count . ($current === null ? '' : ' — current: ' . $current->model);
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
            $meta .= '  no tools';
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
