<?php

declare(strict_types=1);

namespace App\DynamicData\Ddl;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Datenbankspezifische DDL, die der Laravel Schema Builder nicht portabel abbildet.
 *
 * Alles übrige (Tabellen, Spalten, Fremdschlüssel) läuft über den Schema Builder.
 * Eine Implementierung pro Treiber; Auswahl zur Laufzeit über DdlDialectResolver.
 */
interface DdlDialect
{
    /** Legt den Namensraum (PostgreSQL/SQL Server: Schema, MySQL/MariaDB: Datenbank) an, falls nötig. */
    public function ensureNamespace(Connection $connection, string $namespace): void;

    /** Entfernt den Namensraum samt Inhalt. */
    public function dropNamespace(Connection $connection, string $namespace): void;

    /** Voll qualifizierter Tabellenname für Schema Builder und Query Builder. */
    public function qualify(string $namespace, string $table): string;

    /** Eindeutigkeit nur über nicht gelöschte Datensätze (SoftDeletes). */
    public function createActiveUniqueIndex(Connection $connection, string $qualifiedTable, string $column, string $indexName): void;

    /** Entfernt den Index, falls vorhanden. */
    public function dropIndex(Connection $connection, string $namespace, string $table, string $indexName): void;

    /** Kann DDL zusammen mit DML zurückgerollt werden? */
    public function supportsTransactionalDdl(): bool;

    /** Größte Länge eines Textfelds (Zeichen), das einen Index bekommt; null = keine feste Grenze. */
    public function maxIndexedStringLength(): ?int;

    /** Ist der Fehler die Größengrenze einer Tabellenzeile? */
    public function isRowSizeError(QueryException $e): bool;

    /** Vom Dialekt angelegte Hilfsspalte (z. B. MariaDB für eindeutige Werte), kein Feld. */
    public function isHelperColumn(string $column): bool;
}
