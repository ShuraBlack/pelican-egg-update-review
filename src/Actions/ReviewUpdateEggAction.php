<?php

namespace ShuraBlack\EggUpdateReview\Actions;

use App\Enums\EggFormat;
use App\Exceptions\Service\InvalidFileUploadException;
use App\Filament\Components\Actions\UpdateEggAction;
use App\Models\Egg;
use App\Services\Eggs\Sharing\EggExporterService;
use App\Services\Eggs\Sharing\EggImporterService;
use Filament\Actions\Action;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\Width;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

class ReviewUpdateEggAction extends UpdateEggAction
{
    /** Egg fields the importer overwrites, as paths in the parsed egg array. */
    private const FIELDS = [
        'name' => 'Name',
        'author' => 'Author',
        'description' => 'Description',
        'tags' => 'Tags',
        'features' => 'Features',
        'docker_images' => 'Docker images',
        'file_denylist' => 'File denylist',
        'startup_commands' => 'Startup commands',
        'config.files' => 'Configuration files',
        'config.startup' => 'Start configuration',
        'config.logs' => 'Log configuration',
        'config.stop' => 'Stop command',
        'scripts.installation.script' => 'Install script',
        'scripts.installation.container' => 'Install container',
        'scripts.installation.entrypoint' => 'Install entrypoint',
        'meta.update_url' => 'Update URL',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresConfirmation(false);
        $this->modalWidth(Width::FiveExtraLarge);
        $this->modalHeading(fn (Egg $egg) => "Review update: $egg->name");
        $this->modalDescription('Changed fields only. Pick per field whether to take the update or keep the current value.');
        $this->modalSubmitActionLabel('Apply update');
        $this->modalSubmitAction(fn (Action $action) => $action->color('success'));

        $this->schema(function (Egg $egg) {
            try {
                $changes = self::changes(...self::states($egg));
            } catch (Throwable $exception) {
                return [Text::make('Could not load the update: ' . $exception->getMessage())->color('danger')];
            }

            if (!$changes) {
                return [Text::make('No differences found. Applying only refreshes the egg from its update URL.')];
            }

            return array_map(fn (array $change) => Section::make($change['label'])
                ->collapsible()
                ->compact()
                ->schema([
                    Html::make(self::diffHtml($change['old'], $change['new'])),
                    ToggleButtons::make("pick.{$change['key']}")
                        ->hiddenLabel()
                        ->inline()
                        ->options(['new' => 'Take update', 'old' => 'Keep current'])
                        ->colors(['new' => 'success', 'old' => 'warning'])
                        ->default('new')
                        ->required(),
                ]), $changes);
        });

        $this->action(function (Egg $egg, array $data) {
            try {
                [$old, $new] = self::states($egg);
                $keep = fn (string $key) => ($data['pick'][$key] ?? 'new') === 'old';

                $merged = $new;
                foreach (array_keys(self::FIELDS) as $path) {
                    if ($keep(self::fieldKey($path))) {
                        Arr::set($merged, $path, Arr::get($old, $path));
                    }
                }

                [$oldVars, $newVars] = [self::variables($old), self::variables($new)];
                $merged['variables'] = [];
                foreach (array_keys($newVars + $oldVars) as $env) {
                    // Keeping a variable that upstream removed stops the importer from deleting it (and its server values).
                    $variable = $keep("v_$env") ? ($oldVars[$env] ?? null) : ($newVars[$env] ?? null);
                    if ($variable) {
                        $merged['variables'][] = $variable;
                    }
                }

                // fromParsed() is protected; it's the importer's own save path, minus the download.
                (new class(app(ConnectionInterface::class)) extends EggImporterService
                {
                    public function apply(array $parsed, Egg $egg): Egg
                    {
                        return $this->fromParsed($parsed, $egg);
                    }
                })->apply($merged, $egg);

                cache()->forget("eggs.$egg->uuid.update");
                cache()->forget("egg-update-review.$egg->uuid");
            } catch (Throwable $exception) {
                Notification::make()
                    ->title(trans('admin/egg.update_failed', ['egg' => $egg->name]))
                    ->body(trans('admin/egg.update_error', ['error' => $exception->getMessage()]))
                    ->danger()
                    ->send();

                report($exception);

                return;
            }

            Notification::make()
                ->title(trans('admin/egg.update_success', ['egg' => $egg->name]))
                ->body(trans('admin/egg.updated_from', ['url' => $egg->update_url]))
                ->success()
                ->send();
        });
    }

    /**
     * Current egg and upstream egg, both run through the importer's parser so they share one shape.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function states(Egg $egg): array
    {
        $importer = app(EggImporterService::class);
        $old = $importer->parse(app(EggExporterService::class)->handle($egg->id, EggFormat::JSON), EggFormat::JSON);

        // Cached so the diff you reviewed is the one applied, even if upstream changes meanwhile.
        $new = cache()->remember("egg-update-review.$egg->uuid", 600, function () use ($egg, $importer) {
            // Same format detection and timeouts as EggImporterService::fromUrl().
            $format = match (strtolower(pathinfo(parse_url($egg->update_url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION))) {
                'yaml', 'yml' => EggFormat::YAML,
                'json' => EggFormat::JSON,
                default => throw new InvalidFileUploadException('Unsupported file format.'),
            };

            return $importer->parse(Http::timeout(5)->connectTimeout(1)->get($egg->update_url)->throw()->body(), $format);
        });

        return [$old, $new];
    }

    /**
     * @return list<array{key: string, label: string, old: string, new: string}>
     */
    private static function changes(array $old, array $new): array
    {
        $changes = [];

        foreach (self::FIELDS as $path => $label) {
            $a = self::toText(Arr::get($old, $path));
            $b = self::toText(Arr::get($new, $path));
            if ($a !== $b) {
                $changes[] = ['key' => self::fieldKey($path), 'label' => $label, 'old' => $a, 'new' => $b];
            }
        }

        [$oldVars, $newVars] = [self::variables($old), self::variables($new)];
        foreach (array_keys($newVars + $oldVars) as $env) {
            $a = isset($oldVars[$env]) ? self::toText(self::comparable($oldVars[$env])) : '';
            $b = isset($newVars[$env]) ? self::toText(self::comparable($newVars[$env])) : '';
            if ($a !== $b) {
                $state = match (true) {
                    !isset($oldVars[$env]) => ' (new)',
                    !isset($newVars[$env]) => ' (removed upstream)',
                    default => '',
                };
                $changes[] = ['key' => "v_$env", 'label' => "Variable $env$state", 'old' => $a, 'new' => $b];
            }
        }

        return $changes;
    }

    private static function fieldKey(string $path): string
    {
        return 'f_' . str_replace('.', '_', $path);
    }

    /** @return array<string, array<string, mixed>> */
    private static function variables(array $egg): array
    {
        return collect($egg['variables'] ?? [])->keyBy('env_variable')->all();
    }

    /** Only the variable fields the importer writes, in one type each (DB gives bools/arrays, files give 0/1 and "a|b"). */
    private static function comparable(array $variable): array
    {
        $rules = $variable['rules'] ?? [];

        return [
            'name' => $variable['name'] ?? '',
            'description' => $variable['description'] ?? '',
            'default_value' => (string) ($variable['default_value'] ?? ''),
            'user_viewable' => (bool) ($variable['user_viewable'] ?? false),
            'user_editable' => (bool) ($variable['user_editable'] ?? false),
            'rules' => array_values(array_filter(is_array($rules) ? $rules : explode('|', (string) $rules), fn ($rule) => $rule !== '')),
        ];
    }

    /** Text form of a value; JSON strings are pretty-printed so formatting alone never shows as a change. */
    private static function toText(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                return str_replace("\r\n", "\n", $value);
            }
            $value = $decoded;
        }

        if ($value === null || $value === []) {
            return '';
        }

        return str_replace("\r\n", "\n", json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Line diff via LCS, unchanged runs collapsed to 3 lines of context like git diff.
     *
     * @return list<array{0: string, 1: string}> [op, line], op is ' ', '-', '+' or '…'
     */
    public static function diffLines(string $old, string $new): array
    {
        $a = $old === '' ? [] : explode("\n", $old);
        $b = $new === '' ? [] : explode("\n", $new);
        [$n, $m] = [count($a), count($b)];

        $ops = [];
        if ($n * $m > 250000) {
            // shortcut: huge values show as full replace instead of a real diff, add a smarter diff if that ever matters.
            $ops = [...array_map(fn ($line) => ['-', $line], $a), ...array_map(fn ($line) => ['+', $line], $b)];
        } else {
            $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
            for ($i = $n - 1; $i >= 0; $i--) {
                for ($j = $m - 1; $j >= 0; $j--) {
                    $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
                }
            }

            [$i, $j] = [0, 0];
            while ($i < $n || $j < $m) {
                if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                    $ops[] = [' ', $a[$i++]];
                    $j++;
                } elseif ($j >= $m || ($i < $n && $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
                    $ops[] = ['-', $a[$i++]];
                } else {
                    $ops[] = ['+', $b[$j++]];
                }
            }
        }

        $visible = [];
        foreach ($ops as $index => [$op]) {
            if ($op !== ' ') {
                for ($k = max(0, $index - 3); $k <= min(count($ops) - 1, $index + 3); $k++) {
                    $visible[$k] = true;
                }
            }
        }

        $out = [];
        foreach ($ops as $index => $op) {
            if (isset($visible[$index])) {
                $out[] = $op;
            } elseif (end($out) !== ['…', '']) {
                $out[] = ['…', ''];
            }
        }

        return $out;
    }

    private static function diffHtml(string $old, string $new): string
    {
        $colors = ['-' => 'rgba(239,68,68,.18)', '+' => 'rgba(34,197,94,.18)', ' ' => 'transparent', '…' => 'transparent'];

        $rows = array_map(fn (array $op) => sprintf(
            '<div style="white-space:pre;padding:0 .5rem;background:%s%s">%s %s</div>',
            $colors[$op[0]],
            $op[0] === '…' ? ';opacity:.5' : '',
            e($op[0]),
            e($op[1]),
        ), self::diffLines($old, $new));

        return '<div style="font-family:ui-monospace,monospace;font-size:.75rem;line-height:1.25rem;max-height:24rem;overflow:auto;border:1px solid rgba(127,127,127,.3);border-radius:.375rem">'
            . implode('', $rows) . '</div>';
    }
}
