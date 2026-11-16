<?php

declare(strict_types=1);

namespace App\ExternalData;

use App\DynamicData\DataPermissions;
use App\DynamicData\DetailRelations;
use App\DynamicData\Schema\IdentifierValidator;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Massenverknüpfung: viele Tabellen einer externen Datenbank auf einmal anbinden. Je Tabelle
 * gilt die Logik der Einzel-Anbindung (Schlüssel, Typen, CHECK-Regeln, Rechte, Menü); die
 * Verknüpfungen über Fremdschlüssel werden einmal am Ende ermittelt (finish()). Auf Wunsch
 * richtet finish() daraus auch Master-Detail-Beziehungen für die neu angebundenen Tabellen ein.
 *
 * Sammelvorgaben (Aktiv, Export, Anlegen/Bearbeiten/Löschen, Menügruppe, Rollen) gelten, soweit
 * die Tabelle sie erlaubt; Schreiben braucht einen eindeutigen Schlüssel und eine Verbindung,
 * die nicht „nur lesen“ ist. Jede Tabelle wird für sich angebunden: Scheitert eine, laufen die
 * übrigen weiter.
 */
final class BulkConnector
{
    public const string CONNECTED = 'connected';

    public const string READ_ONLY = 'read_only';

    public const string SKIPPED = 'skipped';

    public const string FAILED = 'failed';

    /** Portion je Anfrage in der Oberfläche (PHP-Zeitlimits beim Hoster). */
    public const int CHUNK = 10;

    private const int MAX_NAME_LENGTH = 63;

    /** Höchstens so viele automatische Details je Tabelle; darüber (z. B. Nachschlagetabellen) keine. */
    public const int MAX_AUTO_DETAILS = 5;

    private int $maxDetails = self::MAX_AUTO_DETAILS;

    public function __construct(
        private readonly SchemaInspector $inspector,
        private readonly TableConnectorService $connector,
        private readonly IdentifierValidator $identifiers,
    ) {}

    /**
     * Tabellen und Views eines Schemas mit dem vorgeschlagenen SAFT-Namen und – falls schon
     * angebunden – dem Namen der vorhandenen Anbindung.
     *
     * @return array<string, array{name: string, kind: string, keys: string, comment: ?string, proposed: string, connected: ?string}>
     */
    public function candidates(DatabaseConnection $connection, string $schema, string $prefix = ''): array
    {
        $connected = CustomTable::query()->external()
            ->where('database_connection_id', $connection->id)
            ->where('external_schema', $schema)
            ->pluck('name', 'external_table');
        $rows = [];

        foreach ($this->inspector->tables($connection, $schema) as $table) {
            $rows[$table->name] = [
                'name' => $table->name,
                'kind' => $table->kind,
                'keys' => implode(', ', $table->keyColumns),
                'comment' => $table->comment,
                'proposed' => self::proposedName($table->name, $prefix),
                'connected' => $connected[$table->name] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * SAFT-Name aus dem externen Namen: Präfix, CamelCase getrennt, Kleinbuchstaben, andere Zeichen als Unterstrich,
     * Beginn mit einem Buchstaben, höchstens 63 Zeichen.
     */
    public static function proposedName(string $table, string $prefix = ''): string
    {
        // CamelCase trennen (tblOrder → tbl_order), durchgehend Großes bleibt ein Wort (ORDERS → orders).
        $name = strtolower(trim($prefix).(string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $table));
        $name = (string) preg_replace('/[^a-z0-9_]+/', '_', $name);
        $name = trim((string) preg_replace('/_+/', '_', $name), '_');

        if ($name === '' || ! ctype_alpha($name[0])) {
            $name = 't_'.$name;
        }

        return substr($name, 0, self::MAX_NAME_LENGTH);
    }

    /**
     * Bindet die genannten Tabellen an (eine Portion). Verknüpfungen danach mit finish().
     *
     * @param  list<string>  $tables  externe Tabellennamen
     * @param  array{prefix?: string, is_active?: bool, export_enabled?: bool, allow_create?: bool, allow_update?: bool, allow_delete?: bool, menu_group?: string|null, roles?: list<int|string>}  $options
     * @return list<array{table: string, name: string, status: string, message: string}>
     */
    public function connect(DatabaseConnection $connection, string $schema, array $tables, array $options): array
    {
        $results = [];
        $connected = CustomTable::query()->external()
            ->where('database_connection_id', $connection->id)
            ->where('external_schema', $schema)
            ->pluck('name', 'external_table');

        foreach ($tables as $external) {
            $name = self::proposedName($external, (string) ($options['prefix'] ?? ''));
            $results[] = isset($connected[$external])
                ? self::result($external, (string) $connected[$external], self::SKIPPED, __('table_connector.bulk.already_connected', ['name' => $connected[$external]]))
                : $this->connectOne($connection, $schema, $external, $name, $options);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $results;
    }

    /**
     * Verknüpfungen über Fremdschlüssel einmal für alle Tabellen der Verbindung; danach, falls
     * gewünscht, Details für die neu angebundenen Tabellen (Namen in SAFT).
     *
     * @param  list<string>  $connected
     */
    public function finish(DatabaseConnection $connection, array $connected = [], bool $details = false): BulkFinishResult
    {
        $links = $this->connector->applyForeignKeys($connection);

        return $details ? $this->configureDetails($connection, $connected, $links) : new BulkFinishResult($links);
    }

    /** Grenze für automatische Details herabsetzen (Tests). */
    public function limitDetails(int $max): self
    {
        $this->maxDetails = max(1, $max);

        return $this;
    }

    /**
     * Je neu angebundener Tabelle ohne eigene Details: alle Verknüpfungen von Tabellen derselben
     * Verbindung auf sie, höchstens maxDetails, sortiert nach Bezeichnung der Detailtabelle.
     *
     * @param  list<string>  $connected
     */
    private function configureDetails(DatabaseConnection $connection, array $connected, int $links): BulkFinishResult
    {
        $configured = [];
        $tooMany = [];
        $parents = CustomTable::query()->external()
            ->where('database_connection_id', $connection->id)
            ->whereIn('name', $connected)
            ->orderBy('name')
            ->get();

        foreach ($parents as $parent) {
            if (! empty($parent->detail_field_ids)) {
                continue;
            }

            $fields = DetailRelations::fieldCandidates($parent->name)
                ->filter(fn (CustomField $f): bool => $f->custom_table->database_connection_id === $connection->id)
                ->sortBy(fn (CustomField $f): string => mb_strtolower((string) $f->custom_table->label).'|'.$f->sort_order)
                ->values();

            if ($fields->isEmpty()) {
                continue;
            }

            if ($fields->count() > $this->maxDetails) {
                $tooMany[$parent->name] = $fields->count();

                continue;
            }

            try {
                $this->connector->update($parent, ['detail_field_ids' => $fields->pluck('id')->all()]);
                $configured[$parent->name] = $fields->map(fn (CustomField $f): string => $f->custom_table->name.'.'.$f->name)->all();
            } catch (BusinessRuleException) {
                // Nicht einrichtbar (z. B. Tabelle ohne einzelnen Schlüssel): Anbindung bleibt, nur ohne Details.
            }
        }

        return new BulkFinishResult($links, $configured, $tooMany);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{table: string, name: string, status: string, message: string}
     */
    private function connectOne(DatabaseConnection $connection, string $schema, string $external, string $name, array $options): array
    {
        try {
            $this->identifiers->assertValidTableName($name);
        } catch (BusinessRuleException $e) {
            return self::result($external, $name, self::SKIPPED, $e->getMessage());
        }

        if (CustomTable::query()->where('name', $name)->exists()) {
            return self::result($external, $name, self::SKIPPED, __('table_connector.bulk.name_taken', ['name' => $name]));
        }

        try {
            $table = $this->connector->connect($connection, $schema, $external, [
                'name' => $name,
                'menu_group' => filled($options['menu_group'] ?? null) ? (string) $options['menu_group'] : null,
            ], linkForeignKeys: false);
        } catch (BusinessRuleException $e) {
            return self::result($external, $name, self::SKIPPED, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return self::result($external, $name, self::FAILED, __('table_connector.bulk.failed', ['message' => mb_substr($e->getMessage(), 0, 200)]));
        }

        $this->connector->update($table, [
            'is_active' => (bool) ($options['is_active'] ?? true),
            'export_enabled' => (bool) ($options['export_enabled'] ?? false),
        ]);

        [$written, $note] = $this->allowWriting($table, $connection, $options);
        $this->grantRoles($table, (array) ($options['roles'] ?? []), $written, (bool) ($options['export_enabled'] ?? false));

        return $note === null
            ? self::result($external, $name, self::CONNECTED, __('table_connector.bulk.connected'))
            : self::result($external, $name, self::READ_ONLY, $note);
    }

    /**
     * Schreiben freischalten, soweit möglich.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: list<string>, 1: ?string} freigeschaltete Aktionen, Hinweis bei „nur lesbar“
     */
    private function allowWriting(CustomTable $table, DatabaseConnection $connection, array $options): array
    {
        $wanted = array_keys(array_filter(CustomTable::WRITE_ACTIONS, static fn (string $flag): bool => (bool) ($options[$flag] ?? false)));

        if ($wanted === []) {
            return [[], null];
        }

        if ($connection->is_read_only) {
            return [[], __('table_connector.bulk.read_only_connection')];
        }

        try {
            $this->connector->update($table, array_fill_keys(array_map(static fn (string $a): string => CustomTable::WRITE_ACTIONS[$a], $wanted), true));

            return [$wanted, null];
        } catch (BusinessRuleException $e) {
            return [[], __('table_connector.bulk.read_only', ['reason' => $e->getMessage()])];
        }
    }

    /**
     * Gewählten Rollen das Anzeigerecht geben, dazu die freigeschalteten Schreib- und Exportrechte.
     *
     * @param  list<int|string>  $roles
     * @param  list<string>  $written
     */
    private function grantRoles(CustomTable $table, array $roles, array $written, bool $export): void
    {
        if ($roles === []) {
            return;
        }

        $actions = ['view', ...$written, ...($export ? ['export'] : [])];
        $permissions = array_map(static fn (string $action): string => DataPermissions::name($table->name, $action), $actions);

        foreach (Role::query()->whereKey($roles)->get() as $role) {
            /** @var Role $role */
            $role->givePermissionTo($permissions);
        }
    }

    /**
     * @return array{table: string, name: string, status: string, message: string}
     */
    private static function result(string $table, string $name, string $status, string $message): array
    {
        return ['table' => $table, 'name' => $name, 'status' => $status, 'message' => $message];
    }
}
