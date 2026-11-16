<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DynamicData\DataMenu;
use App\ExternalData\BulkConnector;
use App\Models\DatabaseConnection;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Massenverknüpfung im Reiter der Datenbankverbindung: externe Tabellen auswählen, Vorgaben
 * setzen, in Portionen anbinden (wire:poll), Ergebnis je Tabelle anzeigen.
 */
class BulkConnectTables extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public int $connectionId;

    /** Noch anzubindende externe Tabellen (Warteschlange der laufenden Massenverknüpfung). */
    #[Locked]
    public array $queue = [];

    #[Locked]
    public string $queueSchema = '';

    /** @var array<string, mixed> Sammelvorgaben der laufenden Massenverknüpfung */
    #[Locked]
    public array $options = [];

    /** @var list<array{table: string, name: string, status: string, message: string}> */
    #[Locked]
    public array $results = [];

    #[Locked]
    public int $total = 0;

    public function mount(int $connectionId): void
    {
        $this->connectionId = $connectionId;
        abort_unless($this->allowed(), 403);
    }

    public function connection(): DatabaseConnection
    {
        return DatabaseConnection::query()->findOrFail($this->connectionId);
    }

    private function allowed(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('table_connector.create');
    }

    private function schemaFilter(?array $filters): string
    {
        $schema = $filters['schema']['value'] ?? null;

        return filled($schema) ? (string) $schema : $this->connection()->schemaName();
    }

    public function table(Table $table): Table
    {
        $connection = $this->connection();

        return $table
            ->identifier('bulk-connect.'.$connection->id)
            ->records(function (?array $filters, ?string $search, ?string $sortColumn, ?string $sortDirection) use ($connection): array {
                try {
                    $rows = app(BulkConnector::class)->candidates($connection, $this->schemaFilter($filters));
                } catch (Throwable $e) {
                    report($e);

                    return [];
                }

                if (filled($search)) {
                    $rows = array_filter($rows, static fn (array $row): bool => str_contains(mb_strtolower($row['name'].' '.$row['comment']), mb_strtolower((string) $search)));
                }

                $status = $filters['status']['value'] ?? null;

                if (filled($status)) {
                    $rows = array_filter($rows, static fn (array $row): bool => ($status === 'open') === ($row['connected'] === null));
                }

                if ($sortColumn !== null) {
                    uasort($rows, static fn (array $a, array $b): int => strnatcasecmp((string) ($a[$sortColumn] ?? ''), (string) ($b[$sortColumn] ?? '')) * ($sortDirection === 'desc' ? -1 : 1));
                }

                return $rows;
            })
            ->columns([
                TextColumn::make('name')->label(__('table_connector.bulk.columns.table'))->fontFamily('mono')->searchable()->sortable()
                    ->description(fn (array $record): ?string => $record['comment']),
                TextColumn::make('kind')->label(__('table_connector.bulk.columns.kind'))->badge()->sortable()
                    ->formatStateUsing(fn (string $state): string => __("table_connector.kinds.{$state}")),
                TextColumn::make('keys')->label(__('table_connector.bulk.columns.key'))->fontFamily('mono')->placeholder('—'),
                TextColumn::make('proposed')->label(__('table_connector.bulk.columns.proposed'))->fontFamily('mono'),
                TextColumn::make('connected')->label(__('table_connector.bulk.columns.connected'))->fontFamily('mono')->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('schema')
                    ->label(__('table_connector.form.schema'))
                    ->options(fn (): array => array_combine($names = $this->schemaOptions(), $names))
                    ->default($connection->schemaName())
                    ->selectablePlaceholder(false),
                SelectFilter::make('status')
                    ->label(__('table_connector.bulk.columns.connected'))
                    ->options(['open' => __('table_connector.bulk.status_open'), 'connected' => __('table_connector.bulk.status_connected')]),
            ])
            ->checkIfRecordIsSelectableUsing(fn (array $record): bool => $record['connected'] === null)
            ->toolbarActions([$this->connectAction()])
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(__('table_connector.bulk.empty'));
    }

    /** @return list<string> */
    private function schemaOptions(): array
    {
        $connection = $this->connection();
        $schemas = (array) ($connection->known_schemas ?? []);

        return array_values(array_unique([$connection->schemaName(), ...array_map('strval', $schemas)]));
    }

    private function connectAction(): BulkAction
    {
        $readOnly = $this->connection()->is_read_only;
        $canGrant = Auth::user()?->can('roles.update') ?? false;

        return BulkAction::make('bulkConnect')
            ->label(__('table_connector.bulk.action'))
            ->icon('heroicon-o-link')
            ->modalHeading(__('table_connector.bulk.modal_heading'))
            ->modalDescription(fn (Collection $records): string => trans_choice('table_connector.bulk.modal_description', $records->count(), ['count' => $records->count()]))
            ->modalSubmitActionLabel(__('table_connector.bulk.submit'))
            ->authorize(fn (): bool => $this->allowed())
            ->schema([
                TextInput::make('prefix')
                    ->label(__('table_connector.bulk.prefix'))
                    ->helperText(__('table_connector.bulk.prefix_help'))
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->maxLength(20),
                Grid::make(2)->schema([
                    Toggle::make('is_active')->label(__('common.active'))->default(true),
                    Toggle::make('export_enabled')->label(__('tables.form.export_enabled')),
                    Toggle::make('allow_create')->label(__('table_connector.form.allow_create'))->disabled($readOnly),
                    Toggle::make('allow_update')->label(__('table_connector.form.allow_update'))->disabled($readOnly),
                    Toggle::make('allow_delete')->label(__('table_connector.form.allow_delete'))->disabled($readOnly),
                ]),
                Callout::make(__('table_connector.bulk.read_only_connection'))->warning()->visible($readOnly),
                Toggle::make('details')
                    ->label(__('table_connector.bulk.details'))
                    ->helperText(__('table_connector.bulk.details_help', ['max' => BulkConnector::MAX_AUTO_DETAILS]))
                    ->default(true),
                Select::make('menu_group')
                    ->label(__('table_connector.bulk.menu_group'))
                    ->options(fn (): array => ['' => __('table_connector.bulk.no_menu')] + app(DataMenu::class)->groupOptions())
                    ->default(app(DataMenu::class)->defaultGroup())
                    ->selectablePlaceholder(false),
                Select::make('roles')
                    ->label(__('table_connector.bulk.roles'))
                    ->helperText(__('table_connector.bulk.roles_help'))
                    ->multiple()
                    ->options(fn (): array => Role::query()->where('name', '!=', User::ROLE_SUPER_ADMIN)->orderBy('name')->pluck('name', 'id')->all())
                    ->visible($canGrant),
            ])
            ->action(function (Collection $records, array $data): void {
                $this->queue = $records->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all();
                $this->queueSchema = $this->schemaFilter($this->tableFilters);
                $this->options = [...$data, 'roles' => array_map('intval', (array) ($data['roles'] ?? []))];
                $this->results = [];
                $this->total = count($this->queue);
            })
            ->deselectRecordsAfterCompletion();
    }

    /** Nächste Portion; von der Ansicht per wire:poll aufgerufen, solange die Warteschlange läuft. */
    public function processNext(): void
    {
        if ($this->queue === [] || ! $this->allowed()) {
            return;
        }

        $connector = app(BulkConnector::class);
        $connection = $this->connection();
        $chunk = array_splice($this->queue, 0, BulkConnector::CHUNK);
        $this->results = [...$this->results, ...$connector->connect($connection, $this->queueSchema, $chunk, $this->options)];

        if ($this->queue !== []) {
            return;
        }

        $connected = array_values(array_column(array_filter(
            $this->results,
            static fn (array $r): bool => in_array($r['status'], [BulkConnector::CONNECTED, BulkConnector::READ_ONLY], true),
        ), 'name'));
        $finish = $connector->finish($connection, $connected, (bool) ($this->options['details'] ?? false));
        $counts = array_count_values(array_column($this->results, 'status'));

        Notification::make()
            ->title(__('table_connector.bulk.done'))
            ->body(implode(' ', array_filter([
                __('table_connector.bulk.summary', [
                    'connected' => ($counts[BulkConnector::CONNECTED] ?? 0) + ($counts[BulkConnector::READ_ONLY] ?? 0),
                    'read_only' => $counts[BulkConnector::READ_ONLY] ?? 0,
                    'skipped' => ($counts[BulkConnector::SKIPPED] ?? 0) + ($counts[BulkConnector::FAILED] ?? 0),
                    'links' => $finish->links,
                ]),
                ($this->options['details'] ?? false) ? trans_choice('table_connector.bulk.details_summary', $finish->detailCount(), ['count' => $finish->detailCount()]) : null,
                $finish->tooMany === [] ? null : __('table_connector.bulk.details_too_many', [
                    'max' => BulkConnector::MAX_AUTO_DETAILS,
                    'tables' => implode(', ', array_map(static fn (string $t, int $n): string => "{$t} ({$n})", array_keys($finish->tooMany), $finish->tooMany)),
                ]),
            ])))
            ->success()
            ->persistent()
            ->send();

        $this->resetTable();
    }

    public function clearResults(): void
    {
        $this->results = [];
        $this->total = 0;
    }

    public function render(): View
    {
        return view('livewire.bulk-connect-tables');
    }
}
