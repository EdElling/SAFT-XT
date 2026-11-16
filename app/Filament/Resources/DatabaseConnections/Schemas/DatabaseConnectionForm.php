<?php

declare(strict_types=1);

namespace App\Filament\Resources\DatabaseConnections\Schemas;

use App\ExternalData\ConnectionDriver;
use App\ExternalData\ConnectionRegistry;
use App\Livewire\BulkConnectTables;
use App\Models\DatabaseConnection;
use App\Support\AppLocale;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class DatabaseConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('database_connection')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make(__('db_connections.tabs.connection'))
                            ->icon('heroicon-o-circle-stack')
                            ->schema([
                                self::connectionSection(),
                                self::behaviourSection(),
                                self::testSection(),
                            ]),
                        // Nur beim Bearbeiten: Tabellen lesen geht erst mit gespeicherter, erfolgreich getesteter Verbindung.
                        Tab::make(__('db_connections.tabs.bulk'))
                            ->icon('heroicon-o-link')
                            ->visibleOn('edit')
                            ->schema(fn (?DatabaseConnection $record): array => $record?->last_test_successful === true
                                ? [
                                    Callout::make(__('db_connections.tabs.bulk_intro'))->info(),
                                    Livewire::make(BulkConnectTables::class, ['connectionId' => $record->getKey()])
                                        ->key('bulk-connect-'.$record->getKey()),
                                ]
                                : [Callout::make(__('db_connections.tabs.bulk_untested'))->warning()]),
                    ]),
            ]);
    }

    /**
     * Übernimmt ein neu eingegebenes Passwort; ein leeres Feld behält das gespeicherte.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyPassword(array $data): array
    {
        if (filled($data['new_password'] ?? null)) {
            $data['password'] = (string) $data['new_password'];
        }

        unset($data['new_password']);

        return $data;
    }

    private static function driver(Get $get): ConnectionDriver
    {
        return ConnectionDriver::tryFrom((string) $get('driver')) ?? ConnectionDriver::Pgsql;
    }

    private static function connectionSection(): Section
    {
        return Section::make(__('db_connections.form.general'))
            ->columns(2)
            ->schema([
                TextInput::make('name')
                    ->label(__('common.name'))
                    ->helperText(__('db_connections.form.name_help'))
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                ToggleButtons::make('driver')
                    ->label(__('db_connections.form.driver'))
                    ->options(ConnectionDriver::options())
                    // SQL Server nur mit installierter PHP-Erweiterung pdo_sqlsrv.
                    ->disableOptionWhen(fn (string $value): bool => ! ConnectionDriver::from($value)->isAvailable())
                    ->in(fn (): array => array_values(array_map(static fn (ConnectionDriver $d): string => $d->value, array_filter(ConnectionDriver::cases(), static fn (ConnectionDriver $d): bool => $d->isAvailable()))))
                    ->helperText(fn (): ?string => ConnectionDriver::Sqlsrv->isAvailable() ? null : __('db_connections.form.driver_unavailable'))
                    ->inline()
                    ->required()
                    ->live()
                    ->default(ConnectionDriver::Pgsql->value)
                    ->afterStateUpdated(function (Set $set, Get $get): void {
                        $set('port', self::driver($get)->defaultPort());
                        $set('ssl_mode', self::driver($get)->defaultSslMode());
                    }),
                TextInput::make('host')
                    ->label(__('db_connections.form.host'))
                    ->helperText(__('db_connections.form.host_help'))
                    ->required()
                    ->maxLength(255)
                    ->regex(ConnectionRegistry::HOST_PATTERN)
                    ->validationMessages(['regex' => __('db_connections.errors.invalid_host')]),
                TextInput::make('port')
                    ->label(__('db_connections.form.port'))
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->default(ConnectionDriver::Pgsql->defaultPort()),
                TextInput::make('database')
                    ->label(__('db_connections.form.database'))
                    ->required()
                    ->maxLength(128)
                    ->regex(ConnectionRegistry::NAME_PATTERN)
                    ->validationMessages(['regex' => __('db_connections.errors.invalid_name')]),
                TextInput::make('default_schema')
                    ->label(__('db_connections.form.schema'))
                    ->helperText(__('db_connections.form.schema_help'))
                    ->placeholder(fn (Get $get): string => self::driver($get)->defaultSchema((string) ($get('database') ?: '…')))
                    ->maxLength(63)
                    ->regex(ConnectionRegistry::NAME_PATTERN)
                    ->validationMessages(['regex' => __('db_connections.errors.invalid_name')])
                    ->datalist(fn (?DatabaseConnection $record): array => $record?->known_schemas ?? []),
                TextInput::make('username')
                    ->label(__('db_connections.form.username'))
                    ->required()
                    ->maxLength(128)
                    ->regex(ConnectionRegistry::NAME_PATTERN)
                    ->validationMessages(['regex' => __('db_connections.errors.invalid_name')])
                    ->autocomplete('off'),
                TextInput::make('new_password')
                    ->label(__('db_connections.form.password'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->placeholder(fn (?DatabaseConnection $record): ?string => filled($record?->password) ? __('db_connections.form.password_stored') : null)
                    ->helperText(__('db_connections.form.password_help'))
                    ->maxLength(500),
                Select::make('ssl_mode')
                    ->label(__('db_connections.form.ssl_mode'))
                    ->helperText(__('db_connections.form.ssl_mode_help'))
                    ->options(fn (Get $get): array => array_combine(self::driver($get)->sslModes(), self::driver($get)->sslModes()))
                    ->default(ConnectionDriver::Pgsql->defaultSslMode())
                    // Ohne Angabe verbindet die Registry mit der Vorgabe des Typs; das soll auch so angezeigt werden.
                    ->formatStateUsing(fn (?string $state, Get $get): string => $state ?: self::driver($get)->defaultSslMode())
                    ->selectablePlaceholder(false),
            ]);
    }

    private static function behaviourSection(): Section
    {
        return Section::make(__('db_connections.form.behaviour'))
            ->columns(2)
            ->schema([
                TextInput::make('connect_timeout')
                    ->label(__('db_connections.form.connect_timeout'))
                    ->helperText(__('db_connections.form.timeout_help'))
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(60)
                    ->default(5),
                TextInput::make('statement_timeout')
                    ->label(__('db_connections.form.statement_timeout'))
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(3600)
                    ->default(30),
                Toggle::make('is_read_only')
                    ->label(__('db_connections.form.read_only'))
                    ->helperText(fn (Get $get): string => self::driver($get)->enforcesReadOnly() ? __('db_connections.form.read_only_help') : __('db_connections.form.read_only_help_sqlsrv'))
                    ->default(true),
                Toggle::make('is_active')
                    ->label(__('db_connections.form.active'))
                    ->helperText(__('db_connections.form.active_help'))
                    ->default(true),
                Callout::make(__('db_connections.form.security_hint'))
                    ->info()
                    ->columnSpanFull(),
            ]);
    }

    private static function testSection(): Section
    {
        return Section::make(__('db_connections.test.title'))
            ->visibleOn('edit')
            ->schema([
                Callout::make(fn (?DatabaseConnection $record): string => match ($record?->last_test_successful) {
                    true => __('db_connections.test.success'),
                    false => __('db_connections.test.failure'),
                    null => __('db_connections.test.never'),
                })
                    ->description(fn (?DatabaseConnection $record): ?string => $record?->last_tested_at === null
                        ? null
                        : AppLocale::dateTime($record->last_tested_at).': '.$record->last_test_message)
                    ->color(fn (?DatabaseConnection $record): string => match ($record?->last_test_successful) {
                        true => 'success',
                        false => 'danger',
                        null => 'gray',
                    }),
            ]);
    }
}
