<?php

declare(strict_types=1);

namespace App\DynamicData\Schema;

use App\Exceptions\BusinessRuleException;
use App\Models\DynamicRecord;
use ReflectionClass;
use ReflectionProperty;

/**
 * Einzige Prüfstelle für Tabellen- und Spaltennamen des Table-Designers.
 *
 * Erzwingt u. a., dass kein Name einen Schema-Präfix enthalten kann: Laravel löst
 * "schema.tabelle" auf – ohne diese Prüfung wären Systemtabellen in public erreichbar.
 */
final class IdentifierValidator
{
    /** Kleinbuchstaben, Ziffern, Unterstrich; Start mit Buchstabe; max. 63 Bytes (PostgreSQL). */
    public const string PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

    /** Vom System verwaltete Spalten jeder dynamischen Tabelle. */
    public const array SYSTEM_COLUMNS = ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'];

    /**
     * Reservierte Wörter (SQL-Standard / PostgreSQL / MySQL / SQL Server, Schnittmenge des
     * Problematischen). Auch dort, wo Quoting technisch hilft, bewusst gesperrt.
     */
    private const array RESERVED_WORDS = [
        'all', 'analyse', 'analyze', 'and', 'any', 'array', 'as', 'asc', 'asymmetric', 'authorization', 'between',
        'binary', 'both', 'by', 'case', 'cast', 'check', 'collate', 'collation', 'column', 'concurrently', 'constraint',
        'create', 'cross', 'current_catalog', 'current_date', 'current_role', 'current_schema', 'current_time',
        'current_timestamp', 'current_user', 'database', 'default', 'deferrable', 'delete', 'desc', 'distinct', 'do',
        'drop', 'else', 'end', 'except', 'exec', 'execute', 'exists', 'false', 'fetch', 'for', 'foreign', 'freeze',
        'from', 'full', 'grant', 'group', 'having', 'identity', 'ilike', 'in', 'index', 'initially', 'inner', 'insert',
        'intersect', 'into', 'is', 'isnull', 'join', 'key', 'lateral', 'leading', 'left', 'like', 'limit', 'localtime',
        'localtimestamp', 'merge', 'natural', 'not', 'notnull', 'null', 'offset', 'on', 'only', 'or', 'order', 'outer',
        'overlaps', 'placing', 'primary', 'procedure', 'public', 'references', 'returning', 'revoke', 'right', 'role',
        'rows', 'schema', 'select', 'session_user', 'similar', 'some', 'symmetric', 'system_user', 'table',
        'tablesample', 'then', 'to', 'top', 'trailing', 'trigger', 'true', 'truncate', 'union', 'unique', 'update',
        'user', 'using', 'values', 'variadic', 'verbose', 'view', 'when', 'where', 'window', 'with',
    ];

    /** Eloquent-Interna, die als Attributname still falsche Werte liefern würden. */
    private const array MODEL_RESERVED = [
        'attributes', 'original', 'changes', 'casts', 'relations', 'connection', 'table', 'fillable', 'guarded',
        'hidden', 'visible', 'appends', 'touches', 'observables', 'dates', 'key', 'incrementing', 'exists',
        'timestamps', 'class', 'pivot', 'query',
    ];

    public function assertValidTableName(string $name): void
    {
        $this->assertIdentifier($name, __('tables.list.name'));

        // ds_: Kopien der Datenstände angebundener Tabellen im selben Namensraum.
        if (str_starts_with($name, 'pg_') || str_starts_with($name, 'sql_') || str_starts_with($name, 'ds_')) {
            $this->fail(__('tables.errors.reserved_table', ['name' => $name]));
        }
    }

    public function assertValidColumnName(string $name): void
    {
        $this->assertIdentifier($name, __('tables.what.column_name'));

        if (in_array($name, self::SYSTEM_COLUMNS, true)) {
            $this->fail(__('tables.errors.system_column', ['name' => $name]));
        }

        if (in_array($name, $this->modelReservedNames(), true)) {
            $this->fail(__('tables.errors.reserved_column', ['name' => $name]));
        }
    }

    private function assertIdentifier(string $name, string $what): void
    {
        if (preg_match(self::PATTERN, $name) !== 1) {
            $this->fail(__('tables.errors.invalid_identifier', ['what' => $what, 'name' => $name]));
        }

        if (in_array($name, self::RESERVED_WORDS, true)) {
            $this->fail(__('tables.errors.sql_keyword', ['what' => $what, 'name' => $name]));
        }
    }

    /** Name, den Eloquent intern belegt (für Spalten externer Tabellen, die so nicht nutzbar sind). */
    public function isModelReserved(string $name): bool
    {
        return in_array(mb_strtolower($name), $this->modelReservedNames(), true);
    }

    /**
     * @return list<string>
     */
    private function modelReservedNames(): array
    {
        static $names = null;

        // Öffentliche Eigenschaften werden vor Attributen aufgelöst ($model->exists …).
        return $names ??= array_values(array_unique([
            ...self::MODEL_RESERVED,
            ...array_map(
                static fn (ReflectionProperty $p): string => mb_strtolower($p->getName()),
                (new ReflectionClass(DynamicRecord::class))->getProperties(ReflectionProperty::IS_PUBLIC),
            ),
        ]));
    }

    private function fail(string $message): never
    {
        throw new BusinessRuleException($message, 'dynamic.invalid_identifier');
    }
}
