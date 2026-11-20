<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Actions;

use App\Apps\AppInterfaces;
use App\Apps\AppRemover;
use App\Exceptions\BusinessRuleException;
use App\Models\App;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Lebenszyklus einer App (B-107, Etappe 3): aktivieren, deaktivieren, Entfernen vormerken, abbrechen, ausführen.
 * Entfernen gibt es nur für den Super-Admin (kein zuteilbares Recht): zweifach bestätigt und erst nach einer Wartezeit.
 */
final class AppLifecycleActions
{
    /** @return list<Action> */
    public static function make(): array
    {
        return [self::deactivate(), self::activate(), self::requestRemoval(), self::cancelRemoval(), self::runRemoval()];
    }

    private static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label(__('apps.lifecycle.deactivate'))
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('warning')
            ->visible(fn (App $record): bool => $record->is_active && Gate::allows('update', $record))
            ->requiresConfirmation()
            ->modalDescription(fn (App $record): string => __('apps.lifecycle.deactivate_confirm', ['name' => (string) $record->name])
                // Genutzte Schnittstelle (Etappe 5): verweisende Felder anderer Apps zeigen danach „nicht verfügbar“.
                .(($used = array_merge([], ...array_column(app(AppInterfaces::class)->offeredBy($record), 'usages'))) === []
                    ? '' : ' '.__('apps.interfaces.deactivate_hint', ['usages' => implode(', ', $used)])))
            ->action(function (App $record): void {
                $record->update(['is_active' => false]);
                Notification::make()->success()->title(__('apps.lifecycle.deactivated', ['name' => (string) $record->name]))->send();
            });
    }

    private static function activate(): Action
    {
        return Action::make('activate')
            ->label(__('apps.lifecycle.activate'))
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->visible(fn (App $record): bool => ! $record->is_active && ! $record->removalPending() && Gate::allows('update', $record))
            ->requiresConfirmation()
            ->action(function (App $record): void {
                $record->update(['is_active' => true]);
                Notification::make()->success()->title(__('apps.lifecycle.activated', ['name' => (string) $record->name]))->send();
            });
    }

    private static function requestRemoval(): Action
    {
        return Action::make('requestRemoval')
            ->label(__('apps.removal.action'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (App $record): bool => self::superAdmin() && ! $record->isEmpty() && ! $record->removalPending())
            ->disabled(fn (App $record): bool => $record->is_active)
            ->tooltip(fn (App $record): ?string => $record->is_active ? __('apps.removal.deactivate_first') : null)
            ->modalHeading(fn (App $record): string => __('apps.removal.heading', ['name' => (string) $record->name]))
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalIconColor('danger')
            ->modalSubmitActionLabel(__('apps.removal.submit'))
            ->steps(fn (App $record): array => [
                // 1. Abfrage: Übersicht prüfen und bestätigen.
                Step::make(__('apps.removal.step_overview'))
                    ->schema([
                        View::make('filament.apps.removal-overview')->viewData([
                            'overview' => app(AppRemover::class)->overview($record),
                            'blockers' => app(AppRemover::class)->blockers($record),
                        ]),
                        Checkbox::make('understood')
                            ->label(__('apps.removal.understood'))
                            // Hindernisse prüft das Vormerken nochmals und meldet sie.
                            ->accepted(),
                    ]),
                // 2. Abfrage: Name der App eintippen.
                Step::make(__('apps.removal.step_confirm'))
                    ->schema([
                        TextInput::make('confirmation')
                            ->label(__('apps.removal.type_name', ['name' => (string) $record->name]))
                            ->required()
                            ->in([(string) $record->name])
                            ->validationMessages(['in' => __('apps.removal.confirmation_mismatch', ['name' => (string) $record->name])])
                            ->autocomplete('off'),
                    ]),
            ])
            ->action(function (App $record, array $data): void {
                try {
                    app(AppRemover::class)->request($record, self::user(), (string) ($data['confirmation'] ?? ''));
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->warning()
                    ->title(__('apps.removal.requested', ['name' => (string) $record->name, 'time' => $record->removal_due_at?->format('H:i')]))
                    ->body(__('apps.removal.requested_body'))
                    ->persistent()
                    ->send();
            });
    }

    private static function cancelRemoval(): Action
    {
        return Action::make('cancelRemoval')
            ->label(__('apps.removal.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->visible(fn (App $record): bool => self::superAdmin() && $record->removalPending())
            ->action(function (App $record): void {
                app(AppRemover::class)->cancel($record, self::user());
                Notification::make()->success()->title(__('apps.removal.cancelled', ['name' => (string) $record->name]))->send();
            });
    }

    /** Ohne Cron (z. B. Strato): nach Ablauf der Wartezeit von Hand ausführen. */
    private static function runRemoval(): Action
    {
        return Action::make('runRemoval')
            ->label(__('apps.removal.run'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (App $record): bool => self::superAdmin() && $record->removalDue())
            ->requiresConfirmation()
            ->modalHeading(fn (App $record): string => __('apps.removal.run_heading', ['name' => (string) $record->name]))
            ->modalDescription(__('apps.removal.run_confirm'))
            ->action(function (App $record): void {
                $name = (string) $record->name;

                try {
                    $backup = app(AppRemover::class)->run($record, self::user());
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('apps.removal.done', ['name' => $name, 'backup' => $backup]))->persistent()->send();
            });
    }

    private static function superAdmin(): bool
    {
        return auth()->user() instanceof User && auth()->user()->isSuperAdmin();
    }

    private static function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }
}
