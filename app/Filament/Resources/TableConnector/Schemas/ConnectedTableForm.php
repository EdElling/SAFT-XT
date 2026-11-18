<?php

declare(strict_types=1);

namespace App\Filament\Resources\TableConnector\Schemas;

use App\DynamicData\DetailRelations;
use App\DynamicData\Schema\IdentifierValidator;
use App\ExternalData\ColumnInfo;
use App\ExternalData\ConnectionTester;
use App\ExternalData\SchemaInspector;
use App\ExternalData\TableConnectorService;
use App\ExternalData\TableInfo;
use App\Filament\Resources\TableConnector\Pages\EditConnectedTable;
use App\Filament\Support\AppSelect;
use App\Filament\Support\MenuGroupSelect;
use App\Filament\Support\TableLabelHint;
use App\Filament\Support\TableNameRule;
use App\Filament\Support\TranslatableInputs;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Throwable;

class ConnectedTableForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                self::schemaCallout(),
                self::sourceSection(),
                self::sourceSummary(),
                self::masterDataSection(),
                self::writeSection(),
                self::rulesSection(),
                self::detailsSection(),
            ]);
    }

    /** Bearbeiten: Hinweis, wenn sich die externe Tabelle geändert hat (siehe „Neu einlesen“). */
    private static function schemaCallout(): Callout
    {
        return Callout::make(fn (mixed $livewire): string => $livewire->schemaError !== null ? __('table_connector.schema.check_failed') : __('table_connector.schema.title'))
            ->description(fn (mixed $livewire): HtmlString => $livewire->schemaError !== null
                ? new HtmlString(e($livewire->schemaError))
                : new HtmlString(e(__('table_connector.schema.intro')).EditConnectedTable::schemaList($livewire->schemaLines)))
            ->warning()
            ->visible(fn (mixed $livewire): bool => $livewire instanceof EditConnectedTable && ($livewire->schemaLines !== [] || $livewire->schemaError !== null));
    }

    /** Anlegen: Verbindung → Schema → Tabelle oder View. */
    private static function sourceSection(): Section
    {
        return Section::make(__('table_connector.form.source'))
            ->visibleOn('create')
            ->columns(3)
            ->schema([
                Select::make('database_connection_id')
                    ->label(__('table_connector.form.connection'))
                    ->options(fn (): array => DatabaseConnection::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        $set('external_schema', self::connection($state)?->schemaName());
                        $set('external_table', null);
                    }),
                Select::make('external_schema')
                    ->label(__('table_connector.form.schema'))
                    ->options(fn (Get $get): array => self::schemas($get('database_connection_id')))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('external_table', null))
                    ->helperText(fn (Get $get): ?string => filled($get('database_connection_id')) && self::schemas($get('database_connection_id')) === []
                        ? __('table_connector.form.connection_failed')
                        : null),
                Select::make('external_table')
                    ->label(__('table_connector.form.table'))
                    ->options(fn (Get $get): array => self::tableOptions($get('database_connection_id'), $get('external_schema')))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if ($state === null) {
                            return;
                        }

                        $default = (string) config('locales.default');
                        $info = self::table($get('database_connection_id'), $get('external_schema'), $state);
                        $set('name', app(TableConnectorService::class)->suggestName((string) $get('external_schema'), $state));
                        $set("label.{$default}", TableConnectorService::labelFor($state));
                        $set("description.{$default}", $info?->comment);
                    }),
            ]);
    }

    /** Was beim Anbinden übernommen wird: Schlüssel und Spalten, nicht unterstützte mit Grund. */
    private static function sourceSummary(): Text
    {
        return Text::make(function (Get $get): string {
            $connection = self::connection($get('database_connection_id'));
            $schema = (string) $get('external_schema');
            $table = (string) $get('external_table');
            $info = self::table($get('database_connection_id'), $schema, $table);

            if ($connection === null || $info === null) {
                return '';
            }

            try {
                $columns = app(SchemaInspector::class)->columns($connection, $schema, $table);
            } catch (Throwable) {
                return __('table_connector.form.connection_failed');
            }

            $supported = array_filter($columns, static fn (ColumnInfo $c): bool => $c->isSupported());
            $unsupported = array_map(
                static fn (ColumnInfo $c): string => "{$c->name} ({$c->externalType})",
                array_filter($columns, static fn (ColumnInfo $c): bool => ! $c->isSupported()),
            );

            return implode(' ', array_filter([
                __('table_connector.form.summary', ['count' => count($supported)]),
                $info->keyColumns === []
                    ? __('table_connector.form.summary_no_key')
                    : trans_choice('table_connector.form.summary_key', count($info->keyColumns), ['columns' => implode(', ', $info->keyColumns)]),
                $unsupported === [] ? null : __('table_connector.form.summary_unsupported', ['columns' => implode(', ', $unsupported)]),
            ]));
        })
            // Eine Bedingung: visible() würde ein vorheriges visibleOn() ersetzen (sonst auch beim Bearbeiten, mit Abfrage der Quelle).
            ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && filled($get('external_table')));
    }

    private static function masterDataSection(): Section
    {
        return Section::make(__('tables.form.table'))
            ->columns(2)
            ->schema([
                Text::make(fn (?CustomTable $record): string => $record === null ? '' : __('table_connector.form.source_value', [
                    'connection' => (string) $record->databaseConnection?->name,
                    'table' => $record->external_schema.'.'.$record->external_table,
                    'kind' => __('table_connector.kinds.'.$record->external_kind),
                ]))
                    ->visibleOn('edit')
                    ->columnSpanFull(),
                TranslatableInputs::make('label', __('tables.form.display_name'), required: true, configure: static function ($input, string $locale): void {
                    if ($locale === config('locales.default')) {
                        TableLabelHint::apply($input, $locale);
                    }
                }),
                TableNameRule::apply(TextInput::make('name'), physical: false)
                    ->label(__('tables.form.technical_name'))
                    ->helperText(__('table_connector.form.name_help'))
                    ->required()
                    ->maxLength(63)
                    ->regex(IdentifierValidator::PATTERN)
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                Select::make('key_columns')
                    ->label(__('table_connector.form.key_column'))
                    ->helperText(__('table_connector.form.key_column_help'))
                    ->multiple()
                    ->options(fn (?CustomTable $record): array => $record?->fields->where('is_active', true)->mapWithKeys(fn (CustomField $f): array => [$f->name => $f->name])->all() ?? [])
                    ->placeholder(__('table_connector.form.no_key'))
                    ->visibleOn('edit'),
                TranslatableInputs::make('description', __('common.description'), multiline: true, maxLength: 5000),
                AppSelect::make()->visibleOn('create'),
                MenuGroupSelect::make(app: fn (Get $get): mixed => $get('app_id'))
                    ->visibleOn('create'),
                Toggle::make('export_enabled')
                    ->label(__('tables.form.export_enabled'))
                    ->helperText(__('tables.form.export_enabled_help'))
                    ->visibleOn('edit'),
                Toggle::make('is_active')
                    ->label(__('common.active'))
                    ->helperText(__('tables.form.inactive_help'))
                    ->visibleOn('edit'),
            ]);
    }

    /** Schreiben je Tabelle freischalten; nur wo es möglich ist. */
    private static function writeSection(): Section
    {
        $toggle = static fn (string $name): Toggle => Toggle::make($name)
            ->label(__('table_connector.form.'.$name))
            ->disabled(fn (?CustomTable $record, mixed $livewire): bool => $record === null || ! $record->canBeWritable() || ! ($livewire->keyGuaranteed ?? false));

        return Section::make(__('table_connector.form.write'))
            ->description(fn (?CustomTable $record, mixed $livewire): string => match (true) {
                $record !== null && ! $record->canBeWritable() => __('table_connector.form.write_blocked'),
                ! ($livewire->keyGuaranteed ?? false) => __('table_connector.form.write_key_not_unique'),
                default => __('table_connector.form.write_help'),
            })
            ->visibleOn('edit')
            ->columns(3)
            ->schema([$toggle('allow_create'), $toggle('allow_update'), $toggle('allow_delete')]);
    }

    /** CHECK-Regeln der externen Tabelle: was SAFT schon bei der Eingabe prüft und was nur die Datenbank. */
    private static function rulesSection(): Section
    {
        return Section::make(__('table_connector.rules.title'))
            ->description(__('table_connector.rules.help'))
            ->visible(fn (?CustomTable $record): bool => $record !== null && (array) ($record->check_constraints ?? []) !== [])
            ->collapsible()
            ->schema([
                Text::make(fn (?CustomTable $record): HtmlString => new HtmlString(
                    '<ul class="saft-db-rules" style="display:grid;gap:.5rem">'.implode('', array_map(
                        static fn (array $check): string => '<li><span style="font-family:var(--mono-font-family,monospace);font-weight:600">'.e((string) $check['name']).'</span> – '
                            .e(($check['translated'] ?? false) ? __('table_connector.rules.translated') : __('table_connector.rules.database_only'))
                            .'<br><code style="font-size:.8125rem;word-break:break-all">'.e((string) $check['clause']).'</code></li>',
                        (array) ($record?->check_constraints ?? []),
                    )).'</ul>',
                )),
            ]);
    }

    /** Wie im Table-Designer: verknüpfte Tabellen, die unter einem ausgewählten Datensatz erscheinen. */
    private static function detailsSection(): Section
    {
        return Section::make(__('tables.form.details'))
            ->description(__('tables.form.details_help'))
            ->visibleOn('edit')
            ->schema([
                CheckboxList::make('detail_field_ids')
                    ->hiddenLabel()
                    ->options(fn (?CustomTable $record): array => $record === null ? [] : DetailRelations::options($record->name))
                    ->visible(fn (?CustomTable $record): bool => $record !== null && DetailRelations::options($record->name) !== []),
                Text::make(__('table_connector.form.no_details'))
                    ->visible(fn (?CustomTable $record): bool => $record !== null && DetailRelations::options($record->name) === []),
            ]);
    }

    private static function connection(mixed $id): ?DatabaseConnection
    {
        return filled($id) ? once(fn (): ?DatabaseConnection => DatabaseConnection::query()->find((int) $id)) : null;
    }

    /**
     * @return array<string, string>
     */
    private static function schemas(mixed $connectionId): array
    {
        $connection = self::connection($connectionId);

        if ($connection === null) {
            return [];
        }

        return once(function () use ($connection): array {
            try {
                $schemas = app(ConnectionTester::class)->schemas($connection);
            } catch (Throwable) {
                return [];
            }

            return array_combine($schemas, $schemas);
        });
    }

    /**
     * @return list<TableInfo>
     */
    private static function tables(mixed $connectionId, mixed $schema): array
    {
        $connection = self::connection($connectionId);

        if ($connection === null || blank($schema)) {
            return [];
        }

        return once(function () use ($connection, $schema): array {
            try {
                return app(SchemaInspector::class)->tables($connection, (string) $schema);
            } catch (Throwable) {
                return [];
            }
        });
    }

    private static function table(mixed $connectionId, mixed $schema, ?string $name): ?TableInfo
    {
        foreach (self::tables($connectionId, $schema) as $table) {
            if ($table->name === $name) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Bereits angebundene Tabellen bleiben wählbar (z. B. für eine zweite Ansicht), sind aber markiert.
     *
     * @return array<string, string>
     */
    private static function tableOptions(mixed $connectionId, mixed $schema): array
    {
        $options = [];

        foreach (self::tables($connectionId, $schema) as $table) {
            $hints = array_filter([
                $table->isView() ? __('table_connector.kinds.view') : null,
                ! $table->isView() && $table->keyColumns === [] ? __('table_connector.form.no_key') : null,
            ]);
            $options[$table->name] = $table->name.($hints === [] ? '' : ' ('.implode(', ', $hints).')');
        }

        return $options;
    }
}
