<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Actions;

use App\Apps\Definition\AppInstaller;
use App\Apps\Definition\DefinitionGraph;
use App\Apps\Definition\InstallAnalysis;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DataModelPage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Throwable;

/**
 * „App installieren“ (B-107, Etappe 4b): Datei mit einer App-Definition hochladen, Vorschau mit Hinderungsgründen,
 * Hinweisen und Diagramm, dann neu installieren oder als Kopie (neuer Name, neues Präfix). Die App entsteht deaktiviert.
 * Recht apps.install.
 */
final class InstallAppAction
{
    public const string NEW = 'new';

    public const string COPY = 'copy';

    public static function make(): Action
    {
        return Action::make('installApp')
            ->label(__('apps.install.action'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (): bool => auth()->user()?->can('apps.install') ?? false)
            ->modalHeading(__('apps.install.heading'))
            ->modalWidth('4xl')
            ->modalSubmitActionLabel(__('apps.install.submit'))
            ->steps([
                Step::make(__('apps.install.step_file'))
                    ->schema([
                        FileUpload::make('file')
                            ->label(__('apps.install.file'))
                            ->helperText(__('apps.install.file_help'))
                            ->acceptedFileTypes(['application/json', 'text/plain'])
                            ->maxSize(AppInstaller::MAX_BYTES / 1024)
                            ->storeFiles(false)
                            ->required(),
                    ])
                    // Vorgaben für eine Kopie aus der Datei: „Name (Kopie)“ und Präfix mit angehängter 2.
                    ->afterValidation(function (Get $get, Set $set): void {
                        $definition = self::definition($get('file'));
                        $set('copy_name', __('apps.install.copy_name_default', ['name' => (string) (($definition['app']['name'] ?? [])[config('locales.default')] ?? '')]));
                        $set('copy_prefix', rtrim((string) ($definition['app']['prefix'] ?? 'app'), '_').'2');
                    }),
                Step::make(__('apps.install.step_preview'))
                    ->schema([
                        Radio::make('mode')
                            ->label(__('apps.install.mode'))
                            ->options([self::NEW => __('apps.install.mode_new'), self::COPY => __('apps.install.mode_copy')])
                            ->default(self::NEW)
                            ->inline()
                            ->live(),
                        TextInput::make('copy_name')
                            ->label(__('apps.install.copy_name'))
                            ->visible(fn (Get $get): bool => $get('mode') === self::COPY)
                            ->required(fn (Get $get): bool => $get('mode') === self::COPY)
                            ->live(onBlur: true),
                        TextInput::make('copy_prefix')
                            ->label(__('apps.install.copy_prefix'))
                            ->helperText(__('apps.install.copy_prefix_help'))
                            ->visible(fn (Get $get): bool => $get('mode') === self::COPY)
                            ->required(fn (Get $get): bool => $get('mode') === self::COPY)
                            ->live(onBlur: true),
                        View::make('filament.apps.install-preview')->viewData(fn (Get $get): array => self::preview($get)),
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                $user = auth()->user();
                abort_unless($user instanceof User && $user->can('apps.install'), 403);

                try {
                    $installer = app(AppInstaller::class);
                    $app = $installer->install(self::definition($data['file'] ?? null), self::copy($data['mode'] ?? null, $data['copy_name'] ?? null, $data['copy_prefix'] ?? null), $user);
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()
                    ->title(__('apps.install.done', ['name' => (string) $app->name]))
                    ->body(implode("\n", [__('apps.install.done_body'), ...$installer->notes()]))
                    ->persistent()
                    ->send();
            });
    }

    /** @return array{name: string, prefix: string}|null */
    private static function copy(mixed $mode, mixed $name, mixed $prefix): ?array
    {
        return $mode === self::COPY ? ['name' => (string) $name, 'prefix' => (string) $prefix] : null;
    }

    /**
     * Definition aus der hochgeladenen Datei (je Anfrage einmal gelesen).
     *
     * @return array<string, mixed>
     *
     * @throws BusinessRuleException
     */
    private static function definition(mixed $state): array
    {
        static $cache = [];
        $file = collect(Arr::wrap($state))->first(static fn (mixed $f): bool => $f instanceof UploadedFile);

        if (! $file instanceof UploadedFile) {
            throw new BusinessRuleException(__('apps.install.errors.file'));
        }

        return $cache[$file->getRealPath()] ??= app(AppInstaller::class)->read((string) $file->getRealPath());
    }

    /** @return array<string, mixed> Daten der Vorschau */
    private static function preview(Get $get): array
    {
        try {
            $definition = self::definition($get('file'));
            $analysis = app(AppInstaller::class)->analyze($definition, self::copy($get('mode'), $get('copy_name'), $get('copy_prefix')));
        } catch (Throwable $e) {
            return ['analysis' => new InstallAnalysis([], [$e->getMessage()], [], [], false), 'app' => [], 'diagram' => null];
        }

        $graph = DefinitionGraph::build($analysis->definition);

        return [
            'analysis' => $analysis,
            'app' => (array) ($analysis->definition['app'] ?? []),
            'diagram' => $graph->isEmpty() ? null : [...DataModelPage::viewDataFor($graph), 'storageKey' => 'saft.app-install.fields'],
        ];
    }
}
