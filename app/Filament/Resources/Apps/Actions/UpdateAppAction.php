<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Actions;

use App\Apps\Definition\AppInstaller;
use App\Apps\Update\ChangeExecutor;
use App\Apps\Update\ChangePlan;
use App\Apps\Update\ChangePlanner;
use App\Apps\Update\ChangeStep;
use App\Exceptions\BusinessRuleException;
use App\Models\App;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * „Aktualisieren …“ (B-107, Etappe 6): Datei einer neueren Version der App hochladen, Änderungsplan mit Einstufung,
 * Konflikten und Hinderungsgründen ansehen, Schritte wählen und ausführen (vorher gesichert). Recht apps.upgrade.
 */
final class UpdateAppAction
{
    public static function make(): Action
    {
        return Action::make('updateApp')
            ->label(__('apps.update.action'))
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->visible(fn (): bool => auth()->user()?->can('apps.upgrade') ?? false)
            ->modalHeading(fn (App $record): string => __('apps.update.heading', ['name' => (string) $record->name]))
            ->modalWidth('5xl')
            // Nichts zu tun (keine wählbaren Schritte): Der Knopf auf der Seite „Änderungsplan“ heisst „Schliessen“ und
            // schliesst nur den Dialog.
            ->modalSubmitActionLabel(fn (mixed $livewire, App $record): string => self::hasWork($record, $livewire) ? __('apps.update.submit') : __('common.close'))
            ->steps([
                Step::make(__('apps.update.step_file'))
                    ->schema([
                        FileUpload::make('file')
                            ->label(__('apps.update.file'))
                            ->helperText(__('apps.update.file_help'))
                            ->acceptedFileTypes(['application/json', 'text/plain'])
                            ->maxSize(AppInstaller::MAX_BYTES / 1024)
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->afterValidation(function (Get $get, Set $set, App $record): void {
                        $set('steps', self::plan($record, $get('file'))->preselected());
                        $set('confirm', false);
                    }),
                Step::make(__('apps.update.step_plan'))
                    ->schema([
                        View::make('filament.apps.update-plan')->viewData(fn (Get $get, App $record): array => ['plan' => self::plan($record, $get('file'))]),
                        CheckboxList::make('steps')
                            ->label(__('apps.update.steps'))
                            ->helperText(__('apps.update.steps_help'))
                            ->options(fn (Get $get, App $record): array => collect(self::plan($record, $get('file'))->selectable())
                                ->mapWithKeys(static fn (ChangeStep $s): array => [$s->id => new HtmlString(view('filament.apps.update-step', ['step' => $s])->render())])
                                ->all())
                            ->allowHtml()
                            ->visible(fn (Get $get, App $record): bool => self::plan($record, $get('file'))->selectable() !== [])
                            ->live(),
                        Checkbox::make('confirm')
                            ->label(__('apps.update.confirm'))
                            ->visible(fn (Get $get, App $record): bool => self::destructive($record, $get))
                            ->rule(fn (Get $get, App $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                if (self::destructive($record, $get) && ! $value) {
                                    $fail(__('apps.update.errors.confirm'));
                                }
                            }),
                    ]),
            ])
            ->action(function (App $record, array $data, Action $action): void {
                $user = auth()->user();
                abort_unless($user instanceof User && $user->can('apps.upgrade'), 403);

                $plan = self::plan($record, $data['file'] ?? null);

                if ($plan->selectable() === []) {
                    return; // „Schliessen“
                }

                try {
                    $result = app(ChangeExecutor::class)->apply($record, $plan, array_values((array) ($data['steps'] ?? [])), (bool) ($data['confirm'] ?? false), $user);
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()
                    ->title(__('apps.update.done', ['name' => (string) $record->name]))
                    ->body(__('apps.update.done_body', ['count' => count($result['steps']), 'backup' => $result['backup'], 'version' => (string) ($record->fresh()?->version ?? '–')]))
                    ->persistent()
                    ->send();
            });
    }

    /** Bietet der Plan der hochgeladenen Datei wählbare Schritte? (Ohne Datei, im ersten Schritt: ja.) */
    private static function hasWork(App $record, mixed $livewire): bool
    {
        $mounted = (array) ($livewire->mountedActions ?? []);
        $file = ((array) (end($mounted) ?: []))['data']['file'] ?? null;

        return blank($file) || self::plan($record, $file)->selectable() !== [];
    }

    /** Sind zerstörende Schritte gewählt? */
    private static function destructive(App $record, Get $get): bool
    {
        $plan = self::plan($record, $get('file'));

        return collect((array) $get('steps'))->contains(static fn (mixed $id): bool => $plan->step((string) $id)?->level === ChangeStep::DESTRUCTIVE);
    }

    /** Plan aus der hochgeladenen Datei (je Anfrage einmal berechnet). */
    private static function plan(App $record, mixed $state): ChangePlan
    {
        static $cache = [];
        $file = collect(Arr::wrap($state))->first(static fn (mixed $f): bool => $f instanceof UploadedFile);

        if (! $file instanceof UploadedFile) {
            return new ChangePlan([], [__('apps.install.errors.file')], [], []);
        }

        return $cache[$record->id.'|'.$file->getRealPath()] ??= (static function () use ($record, $file): ChangePlan {
            try {
                $definition = app(AppInstaller::class)->read((string) $file->getRealPath());

                return app(ChangePlanner::class)->plan($record, $definition, auth()->user() instanceof User ? auth()->user() : null);
            } catch (Throwable $e) {
                return new ChangePlan([], [$e->getMessage()], [], []);
            }
        })();
    }
}
