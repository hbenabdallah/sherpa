<?php

namespace App\Acp;

use App\Config\ModelProfile;
use App\Platform\ModelInfo;
use App\Platform\SwitchablePlatform;

/**
 * What an editor shows beside its prompt box and lets the user change: the
 * mode, plan first or not, and the model. Said both ways ACP has — session
 * modes, and the configuration options that came after them — since an editor
 * that knows the second ignores the first.
 */
final class SessionConfig
{
    public const MODE = 'mode';
    public const MODEL = 'model';

    private const MODES = [
        'default' => ['Default', 'Reads freely; asks before anything that writes or runs'],
        'plan'    => ['Plan', 'Reads and proposes a plan; nothing is changed until you approve it'],
    ];

    /** @var list<ModelInfo>|null the provider's models, asked once: the list is one request away */
    private ?array $catalogue = null;

    public function __construct(private readonly SwitchablePlatform $platform) {}

    public static function modeId(bool $planning): string
    {
        return $planning ? 'plan' : 'default';
    }

    /** Whether $modeId is plan mode; an unknown one is the editor's mistake. */
    public static function planning(mixed $modeId): bool
    {
        if (!is_string($modeId) || !isset(self::MODES[$modeId])) {
            throw AcpError::invalidParams('Unknown mode: ' . (is_string($modeId) ? $modeId : '?') . '. Sherpa has default and plan.');
        }

        return $modeId === 'plan';
    }

    /** @return array<string, mixed> */
    public function modes(bool $planning): array
    {
        $modes = [];
        foreach (self::MODES as $id => [$name, $description]) {
            $modes[] = ['id' => $id, 'name' => $name, 'description' => $description];
        }

        return ['currentModeId' => self::modeId($planning), 'availableModes' => $modes];
    }

    /** @return list<array<string, mixed>> */
    public function options(bool $planning, ?ModelProfile $model): array
    {
        $modes = [];
        foreach (self::MODES as $id => [$name, $description]) {
            $modes[] = ['value' => $id, 'name' => $name, 'description' => $description];
        }

        $options = [[
            'id'           => self::MODE,
            'name'         => 'Mode',
            'category'     => 'mode',
            'type'         => 'select',
            'currentValue' => self::modeId($planning),
            'options'      => $modes,
        ]];

        if ($model !== null) {
            $options[] = [
                'id'           => self::MODEL,
                'name'         => 'Model',
                'description'  => 'Models of ' . $this->platform->name() . ' that can call tools',
                'category'     => 'model',
                'type'         => 'select',
                'currentValue' => $model->model,
                'options'      => $this->models($model),
            ];
        }

        return $options;
    }

    /**
     * The profile for $name, one of the models offered: its window is what
     * the model takes, the current one kept as it is.
     */
    public function model(mixed $name, ?ModelProfile $current): ModelProfile
    {
        if (!is_string($name) || $name === '') {
            throw AcpError::invalidParams('The model is missing.');
        }

        if ($current !== null && $current->model === $name) {
            return $current;
        }

        $info = null;
        foreach ($this->catalogue() as $offered) {
            if ($offered->name === $name) {
                $info = $offered;
            }
        }

        if ($info === null) {
            throw AcpError::invalidParams($this->platform->name() . " does not offer {$name}.");
        }

        // The list may lack the two fields that matter; this one model is asked about.
        if ($info->contextLength === null || $info->capabilitiesUnknown()) {
            $info = $this->platform->describeModel($name) ?? $info;
        }

        if (!$info->supportsTools()) {
            throw AcpError::invalidParams("{$name} cannot call tools: with it, Sherpa could neither read a file nor run a command.");
        }

        return ModelProfile::suggestFor($info, 'chosen in the editor', $this->platform->backend());
    }

    /** @return list<array<string, string>> */
    private function models(ModelProfile $current): array
    {
        $options = [];
        $listed = false;
        foreach ($this->catalogue() as $info) {
            if (!$info->supportsTools()) {
                continue;
            }

            $listed = $listed || $info->name === $current->model;
            $context = $info->humanContext();
            $options[] = ['value' => $info->name, 'name' => $info->name] + ($context === '?' ? [] : ['description' => "{$context} context"]);
        }

        // The model in use is always there to show, listed by the provider or not.
        if (!$listed) {
            array_unshift($options, ['value' => $current->model, 'name' => $current->model]);
        }

        return $options;
    }

    /** @return list<ModelInfo> */
    private function catalogue(): array
    {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }

        try {
            $models = array_values($this->platform->catalogue());
        } catch (\Throwable $e) {
            fwrite(STDERR, 'sherpa acp: the list of models: ' . $e->getMessage() . "\n");
            $models = [];
        }

        // An empty list is a provider that did not answer: asked again next time.
        if ($models !== []) {
            $this->catalogue = $models;
        }

        return $models;
    }
}
