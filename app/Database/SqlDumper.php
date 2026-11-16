<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Connection;
use PDO;

/**
 * Sicherung einer MySQL- bzw. MariaDB-Datenbank ohne mysqldump – für Webspaces, deren mysqldump fehlt oder zu alt ist
 * (z. B. Strato: mysqldump 5.6 kennt die unsichtbaren berechneten Spalten von MariaDB nicht). Schreibt wie
 * `mysqldump --databases` ein SQL-Skript: Datenbank anlegen, je Tabelle Aufbau (SHOW CREATE TABLE) und Daten als INSERT;
 * berechnete Spalten ohne Werte, Sichten zuletzt. Einspielen mit `mysql`/`mariadb` oder phpMyAdmin.
 *
 * Gelesen wird in einer Transaktion mit gleichbleibendem Stand, Zeile für Zeile (ungepuffert), damit grosse Tabellen
 * den Speicher nicht füllen.
 */
final class SqlDumper
{
    /** Zeilen je INSERT-Anweisung. */
    private const int ROWS_PER_INSERT = 100;

    /** @param  resource  $handle */
    public function dump(Connection $connection, string $database, $handle): void
    {
        $pdo = $connection->getPdo();
        $quoteName = static fn (string $name): string => '`'.str_replace('`', '``', $name).'`';
        $write = static function (string $text) use ($handle): void {
            fwrite($handle, $text);
        };

        $write("-- SAFT SQL dump (PHP) of {$quoteName($database)}, ".now()->toIso8601String()."\n");
        $write("/*!40101 SET NAMES utf8mb4 */;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n\n");
        $write("CREATE DATABASE IF NOT EXISTS {$quoteName($database)};\nUSE {$quoteName($database)};\n\n");

        $connection->statement('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        try {
            $objects = $connection->table('information_schema.tables')
                ->where('table_schema', $database)
                ->orderBy('table_name')
                ->get(['table_name as name', 'table_type as type']);

            foreach ($objects->where('type', 'BASE TABLE') as $table) {
                $this->table($connection, $pdo, $database, (string) $table->name, $quoteName, $write);
            }

            foreach ($objects->where('type', 'VIEW') as $view) {
                $create = (array) $connection->selectOne('SHOW CREATE VIEW '.$quoteName($database).'.'.$quoteName((string) $view->name));
                $write('DROP VIEW IF EXISTS '.$quoteName((string) $view->name).";\n".array_values($create)[1].";\n\n");
            }
        } finally {
            $connection->statement('COMMIT');
        }

        $write("SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n");
    }

    /**
     * @param  \Closure(string): string  $quoteName
     * @param  \Closure(string): void  $write
     */
    private function table(Connection $connection, PDO $pdo, string $database, string $table, \Closure $quoteName, \Closure $write): void
    {
        $qualified = $quoteName($database).'.'.$quoteName($table);
        $create = (array) $connection->selectOne('SHOW CREATE TABLE '.$qualified);
        $write('DROP TABLE IF EXISTS '.$quoteName($table).";\n".array_values($create)[1].";\n\n");

        // Berechnete Spalten (VIRTUAL/STORED GENERATED) erhalten keine Werte – wie bei mysqldump.
        $columns = $connection->table('information_schema.columns')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->orderBy('ordinal_position')
            ->get(['column_name as name', 'extra as extra'])
            ->reject(static fn (object $c): bool => preg_match('/GENERATED|VIRTUAL|STORED|PERSISTENT/i', (string) $c->extra) === 1)
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        if ($columns === []) {
            return;
        }

        $insert = 'INSERT INTO '.$quoteName($table).' ('.implode(', ', array_map($quoteName, $columns)).') VALUES ';
        $rows = [];
        $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            $statement = $pdo->query('SELECT '.implode(', ', array_map($quoteName, $columns)).' FROM '.$qualified);

            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                $rows[] = '('.implode(', ', array_map(static fn (mixed $value): string => $value === null ? 'NULL' : (string) $pdo->quote((string) $value), $row)).')';

                if (count($rows) === self::ROWS_PER_INSERT) {
                    $write($insert.implode(",\n", $rows).";\n");
                    $rows = [];
                }
            }

            $statement->closeCursor();
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }

        if ($rows !== []) {
            $write($insert.implode(",\n", $rows).";\n");
        }

        $write("\n");
    }
}
