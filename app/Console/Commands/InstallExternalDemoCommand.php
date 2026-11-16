<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\BusinessRuleException;
use App\ExternalData\ConnectionDriver;
use App\Models\DatabaseConnection;
use Database\Seeders\ExternalDemoTablesSeeder;
use Database\Seeders\ExternalResearchDatabase;
use Database\Seeders\MySqlResearchDatabase;
use Database\Seeders\SqlServerResearchDatabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Richtet die Demo-Forschungsdatenbank für den Table-Connector ein (Name endet auf _ext).
 * --driver=pgsql (Vorgabe): Schema „forschung“ in einer leer vorhandenen PostgreSQL-Datenbank,
 * Zugangsdaten PGSQL_*. --driver=mysql: Datenbank auf dem MySQL-Server (MYSQL_*), wird bei
 * Bedarf angelegt. --driver=mariadb: ebenso auf dem MariaDB-Server (MARIADB_*). --driver=sqlsrv: Schema „forschung“ in einer vorhandenen SQL-Server-Datenbank
 * (MSSQL_*, Login mit db_owner). Alles geht unabhängig davon, welche Datenbank SAFT selbst nutzt.
 * Legt nur an, was fehlt; löscht nie etwas.
 */
#[Signature('external-demo:install {--database=SAFT2_ext : Name der externen Demo-Datenbank} {--driver=pgsql : Datenbanktyp der Demo: pgsql, mysql, mariadb oder sqlsrv} {--name=Forschung (Demo) : Name der Datenbankverbindung in SAFT} {--without-connection : Nur die Tabellen anlegen, keine Verbindung} {--without-tables : Verbindung anlegen, aber keine Tabellen anbinden}')]
#[Description('Legt die Demo-Forschungsdatenbank und eine Datenbankverbindung dafür an')]
class InstallExternalDemoCommand extends Command
{
    /** Zugangsdaten in der .env je Datenbanktyp. */
    private const array ENV_PREFIX = ['pgsql' => 'PGSQL', 'mysql' => 'MYSQL', 'mariadb' => 'MARIADB', 'sqlsrv' => 'MSSQL'];

    public function handle(): int
    {
        $database = (string) $this->option('database');
        $driver = ConnectionDriver::tryFrom((string) $this->option('driver'));

        if ($driver === null) {
            $this->error('--driver muss pgsql, mysql, mariadb oder sqlsrv sein.');

            return self::FAILURE;
        }

        try {
            $fixture = match ($driver) {
                ConnectionDriver::Pgsql => ExternalResearchDatabase::on($database),
                ConnectionDriver::Mysql => MySqlResearchDatabase::on($database),
                ConnectionDriver::Mariadb => MySqlResearchDatabase::on($database, 'mariadb'),
                ConnectionDriver::Sqlsrv => SqlServerResearchDatabase::on($database),
            };
            $created = $fixture->install();
        } catch (QueryException $e) {
            // Vor RuntimeException: QueryException ist eine (PDO-)RuntimeException.
            $this->error(sprintf(
                '%s-Datenbank %s nicht erreichbar. Zugangsdaten %s_* in der .env prüfen.',
                $driver->label(),
                $database,
                self::ENV_PREFIX[$driver->value],
            ));
            $this->line($e->getPrevious()?->getMessage() ?? $e->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $schema = match ($driver) {
            ConnectionDriver::Pgsql => ExternalResearchDatabase::SCHEMA,
            ConnectionDriver::Mysql, ConnectionDriver::Mariadb => $database,
            ConnectionDriver::Sqlsrv => SqlServerResearchDatabase::SCHEMA,
        };
        $this->info($created
            ? "Schema „{$schema}“ mit Demo-Daten in {$database} angelegt."
            : "Schema „{$schema}“ in {$database} ist bereits vorhanden – unverändert.");

        if ($this->option('without-connection')) {
            return self::SUCCESS;
        }

        $name = (string) $this->option('name');
        $connection = DatabaseConnection::query()->where('name', $name)->first();

        if ($connection !== null) {
            $this->info("Datenbankverbindung „{$name}“ ist bereits vorhanden – unverändert.");

            return $this->connectTables($connection);
        }

        // Server und Benutzer aus PGSQL_*, MYSQL_*, MARIADB_* bzw. MSSQL_*; das Passwort wird verschlüsselt gespeichert.
        $own = config('database.connections.'.$driver->value);
        $connection = DatabaseConnection::create([
            'name' => $name,
            'driver' => $driver,
            'host' => $own['host'],
            'port' => (int) $own['port'],
            'database' => $database,
            'username' => $own['username'],
            'password' => $own['password'],
            'default_schema' => $schema,
            'ssl_mode' => $driver->defaultSslMode(),
            'is_read_only' => true,
        ]);

        $this->info("Datenbankverbindung „{$name}“ angelegt (nur lesen). Unter Administration › Datenbankverbindungen testen.");

        return $this->connectTables($connection);
    }

    /** Tabellen mit Bezeichnungen, Verknüpfungen, Details und Menü (Gruppe „Forschungsdatenbank“). */
    private function connectTables(DatabaseConnection $connection): int
    {
        if ($this->option('without-tables')) {
            return self::SUCCESS;
        }

        try {
            $created = app(ExternalDemoTablesSeeder::class)->install($connection);
        } catch (BusinessRuleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($created > 0
            ? "{$created} Tabellen angebunden (Menü „".ExternalDemoTablesSeeder::GROUP.'“).'
            : 'Die Demo-Tabellen sind bereits angebunden – unverändert.');

        return self::SUCCESS;
    }
}
