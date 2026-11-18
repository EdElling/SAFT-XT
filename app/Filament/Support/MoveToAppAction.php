<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Apps\AppMover;
use App\DynamicData\DataMenu;
use App\Exceptions\BusinessRuleException;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * „In andere App verschieben“ (B-107, Etappe 1b): ein Dialog für die Tabellenliste (Mehrfachauswahl), oberste
 * Menügruppen (die ganze Gruppe) und die Seite „Apps“ (Tabellen einer App wählen). Prüfung und Ausführung: AppMover.
 */
final class MoveToAppAction
{
    /** Table-Designer: gewählte Tabellen. */
    public static function forTables(): BulkAction
    {
        return self::configure(BulkAction::make('moveToApp'), static fn (Collection $records): array => $records->pluck('name')->values()->all())
            ->deselectRecordsAfterCompletion();
    }

    /** Menüs: eine oberste Gruppe samt allen Tabellen darin. */
    public static function forGroup(): Action
    {
        return self::configure(Action::make('moveGroupToApp'), static fn (Menu $record): array => self::groupTables($record), group: true)
            ->label(__('apps.move.action_group'))
            ->visible(static fn (Menu $record): bool => $record->parent_id === null && $record->route === null && $record->app_id !== null && self::groupTables($record) !== []);
    }

    /** Seite „Apps“: Tabellen dieser App auswählen. */
    public static function forApp(): Action
    {
        return self::configure(Action::make('moveTablesOfApp'), static fn (App $record): array => [], source: static fn (App $record): int => $record->id)
            ->label(__('apps.move.action_app'))
            ->visible(static fn (App $record): bool => $record->customTables()->exists());
    }

    /**
     * @param  Closure  $initial  ausgewählte Tabellen (Namen) aus dem Datensatz bzw. den Datensätzen
     * @param  Closure|null  $source  App, aus der Tabellen gewählt werden können
     */
    private static function configure(Action|BulkAction $action, Closure $initial, bool $group = false, ?Closure $source = null): Action|BulkAction
    {
        return $action
            ->label(__('apps.move.action'))
            ->icon('heroicon-o-arrow-right-circle')
            ->authorize(static fn (): bool => auth()->user()?->can('apps.manage') ?? false)
            ->modalHeading(__('apps.move.heading'))
            ->modalWidth('3xl')
            ->modalSubmitActionLabel(__('apps.move.submit'))
            ->fillForm(static fn (Action $action): array => ['tables' => $initial(self::subject($action))])
            ->schema(static function (Action $action) use ($group, $source, $initial): array {
                $fixed = $initial(self::subject($action));
                $sourceApps = $source !== null
                    ? [$source($action->getRecord())]
                    : CustomTable::query()->whereIn('name', $fixed)->pluck('app_id')->filter()->unique()->values()->all();

                return [
                    $group
                        ? Hidden::make('tables')
                        : CheckboxList::make('tables')
                            ->label(__('apps.move.tables'))
                            ->options(static fn (): array => CustomTable::query()->whereIn('app_id', $sourceApps)->get()
                                ->mapWithKeys(static fn (CustomTable $t): array => [$t->name => $t->label.' ('.$t->name.')'])->sort()->all())
                            ->columns(2)
                            ->required()
                            ->live(),
                    Select::make('target')
                        ->label(__('apps.move.target'))
                        ->options(static fn (): array => array_diff_key(AppSelect::options(), array_flip($sourceApps)))
                        ->required()
                        ->native()
                        ->live()
                        // Vorbelegung der erst jetzt sichtbaren Felder: neue Gruppe, neue Rollen mit Namensvorschlag.
                        ->afterStateUpdated(static function (Get $get, Set $set, mixed $state): void {
                            $set('group', AppMover::GROUP_NEW);
                            $target = filled($state) ? App::query()->find((int) $state) : null;

                            foreach (($target === null ? null : self::plan($get))['roles'] ?? [] as $id => $info) {
                                $set("roles.{$id}", AppMover::ROLE_NEW);
                                $set("role_names.{$id}", AppMover::newRoleName(Role::query()->findOrFail($id), $target));
                            }
                        }),
                    Callout::make(__('apps.move.blocked_title'))
                        ->description(static fn (Get $get): HtmlString => new HtmlString('<ul style="list-style:disc;padding-left:1.25rem">'
                            .implode('', array_map(static fn (string $b): string => '<li>'.e($b).'</li>', self::plan($get)['blockers'] ?? [])).'</ul>'))
                        ->danger()
                        ->visible(static fn (Get $get): bool => (self::plan($get)['blockers'] ?? []) !== [])
                        ->footer([
                            Actions::make([
                                Action::make('addConnected')
                                    ->label(__('apps.move.add_connected'))
                                    ->icon('heroicon-o-plus')
                                    ->visible(! $group)
                                    ->action(static function (Get $get, Set $set): void {
                                        $target = App::query()->find((int) $get('target'));

                                        if ($target !== null) {
                                            $set('tables', app(AppMover::class)->connected(array_values((array) $get('tables')), $target));
                                        }
                                    }),
                            ]),
                        ]),
                    Text::make(static function (Get $get): string {
                        $plan = self::plan($get);

                        return __('apps.move.summary', ['tables' => count($plan['tables'] ?? []), 'menus' => $plan['menus'] ?? 0, 'reports' => count($plan['reports'] ?? [])]);
                    })->visible(static fn (Get $get): bool => ($plan = self::plan($get)) !== null && $plan['blockers'] === []),
                    Select::make('group')
                        ->label(__('apps.move.group'))
                        ->options(static fn (Get $get): array => [AppMover::GROUP_NEW => __('apps.move.group_new')]
                            + array_diff_key(app(DataMenu::class)->groupOptions(filled($get('target')) ? (int) $get('target') : null), [DataMenu::NEW_DATA_GROUP => true]))
                        ->default(AppMover::GROUP_NEW)
                        ->native()
                        ->selectablePlaceholder(false)
                        ->required()
                        ->visible(static fn (Get $get): bool => ! $group && filled($get('target'))),
                    Group::make(static function (Get $get): array {
                        $plan = self::plan($get);
                        $target = App::query()->find((int) $get('target'));

                        if ($plan === null || $target === null || $plan['roles'] === []) {
                            return [];
                        }

                        $existing = Role::query()->where('app_id', $target->id)->orderBy('name')->pluck('name', 'id')
                            ->mapWithKeys(static fn (string $name, int $id): array => [(string) $id => __('apps.move.role_existing', ['name' => $name])])->all();
                        $fields = [Text::make(__('apps.move.roles'))];

                        foreach ($plan['roles'] as $id => $info) {
                            $fields[] = Select::make("roles.{$id}")
                                ->label(__('apps.move.role_label', ['name' => $info['name'], 'users' => $info['users']]))
                                ->options([AppMover::ROLE_NEW => __('apps.move.role_new'), AppMover::ROLE_DROP => __('apps.move.role_drop'), ...$existing])
                                ->default(AppMover::ROLE_NEW)
                                ->native()
                                ->selectablePlaceholder(false)
                                ->live();
                            $fields[] = TextInput::make("role_names.{$id}")
                                ->label(__('apps.move.role_name'))
                                ->default(AppMover::newRoleName(Role::query()->findOrFail($id), $target))
                                ->maxLength(125)
                                ->alphaDash()
                                ->visible(static fn (Get $get): bool => ($get("roles.{$id}") ?? AppMover::ROLE_NEW) === AppMover::ROLE_NEW);
                        }

                        return $fields;
                    })->key('roles'),
                ];
            })
            ->action(static function (array $data, Action $action) use ($group): void {
                $record = $action instanceof BulkAction ? null : $action->getRecord();
                $target = App::query()->findOrFail((int) $data['target']);
                $tables = array_values(array_map('strval', (array) ($data['tables'] ?? [])));
                $user = auth()->user();

                try {
                    app(AppMover::class)->move(
                        $tables,
                        $target,
                        $group ? null : ($data['group'] ?? AppMover::GROUP_NEW),
                        (array) ($data['roles'] ?? []),
                        (array) ($data['role_names'] ?? []),
                        $group && $record instanceof Menu ? $record : null,
                        $user instanceof User ? $user : null,
                    );
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('apps.move.done', ['count' => count($tables), 'app' => (string) $target->name]))->send();
            });
    }

    /** Gewählte Datensätze (Massenaktion) bzw. der Datensatz der Zeile. */
    private static function subject(Action $action): mixed
    {
        return $action instanceof BulkAction ? $action->getSelectedRecords() : $action->getRecord();
    }

    /**
     * Plan zur aktuellen Auswahl im Dialog (einmal je Anfrage berechnet).
     *
     * @return array<string, mixed>|null
     */
    private static function plan(Get $get): ?array
    {
        $tables = array_values(array_map('strval', (array) $get('tables')));
        $target = filled($get('target')) ? App::query()->find((int) $get('target')) : null;

        if ($tables === [] || $target === null) {
            return null;
        }

        return once(static fn (): array => app(AppMover::class)->plan($tables, $target));
    }

    /** @return list<string> Tabellen der Dateneinträge in der Gruppe und ihren Untergruppen */
    private static function groupTables(Menu $group): array
    {
        $ids = [$group->id];
        $queue = [$group->id];
        $tables = [];

        while ($queue !== []) {
            $children = Menu::query()->whereIn('parent_id', $queue)->get(['id', 'route', 'route_parameters']);

            foreach ($children as $child) {
                if ($child->route === DataMenu::ROUTE && filled($table = ($child->route_parameters ?? [])['table'] ?? null)) {
                    $tables[] = (string) $table;
                }
            }

            $queue = $children->pluck('id')->all();
            $ids = [...$ids, ...$queue];
        }

        return array_values(array_unique($tables));
    }
}
