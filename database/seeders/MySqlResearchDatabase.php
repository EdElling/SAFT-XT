<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * MySQL- bzw. MariaDB-Fassung der Forschungsdatenbank für den Table-Connector (Demo und Tests). Das
 * Schema ist die Datenbank selbst: Alle Tabellen liegen direkt in <name>_ext (_test, _dusk). Server und
 * Zugangsdaten aus der Verbindung mysql (MYSQL_*) bzw. mariadb (MARIADB_*).
 *
 * Sonderfälle wie bei PostgreSQL, soweit MySQL sie kennt: AUTO_INCREMENT, UUID-Schlüssel mit
 * DEFAULT (uuid()), berechnete Spalte, zusammengesetzter Primärschlüssel, natürlicher Schlüssel,
 * Tabelle ohne Primärschlüssel, View, CHECK-Regeln, JSON, Binärdaten – und ein enum (Projektstatus).
 * MariaDB: UUID als eigener Typ uuid, JSON als longtext mit json_valid(), Muster mit REGEXP.
 *
 * Schutz wie bei ExternalResearchDatabase: nur Datenbanken auf _ext, _ext_test oder _ext_dusk;
 * Leeren und Neuaufbau nur bei _ext_test und _ext_dusk.
 */
final class MySqlResearchDatabase
{
    use ResearchDemoData;

    private function __construct(private readonly Connection $db) {}

    /**
     * Verbindung zur genannten Datenbank auf dem Server der Verbindung mysql (MYSQL_*) bzw. mariadb (MARIADB_*).
     *
     * @param  'mysql'|'mariadb'  $server
     */
    public static function on(string $database, string $server = 'mysql'): self
    {
        if (! in_array($server, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Unbekannter Server: {$server}");
        }

        $name = 'ext_fixture_'.$server;

        if (preg_match('/_ext(_test|_dusk)?$/', $database) !== 1) {
            throw new RuntimeException("Externe Demo-/Testdatenbank abgelehnt: {$database} (Name muss auf _ext, _ext_test oder _ext_dusk enden).");
        }

        DB::purge($name);
        config(['database.connections.'.$name => [...config('database.connections.'.$server), 'database' => '']]);
        $db = DB::connection($name);
        $db->statement('create database if not exists `'.$database.'` character set utf8mb4 collate utf8mb4_unicode_ci');

        DB::purge($name);
        config(['database.connections.'.$name.'.database' => $database]);
        $db = DB::connection($name);

        if ($db->getDatabaseName() !== $database) {
            throw new RuntimeException('Externe Datenbank: unerwartetes Ziel '.$db->getDatabaseName());
        }

        return new self($db);
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    /** Das Schema ist bei MySQL die Datenbank. */
    public function schema(): string
    {
        return $this->db->getDatabaseName();
    }

    public function exists(): bool
    {
        return $this->db->getSchemaBuilder()->hasTable($this->qualified('organisationen'));
    }

    /** Aufbau entspricht dieser Klasse (für Testdatenbanken nach Änderungen am Aufbau). */
    public function isCurrent(): bool
    {
        return $this->exists() && $this->db->getSchemaBuilder()->hasTable($this->qualified('importprotokoll'));
    }

    /** Legt Tabellen und Daten an, falls sie fehlen; löscht nie etwas. */
    public function install(): bool
    {
        if ($this->exists()) {
            return false;
        }

        $this->createTables();
        $this->seed();

        return true;
    }

    /** Nur für Testdatenbanken: alles verwerfen und neu aufbauen. */
    public function reset(): void
    {
        if (preg_match('/_ext_(test|dusk)$/', $this->schema()) !== 1) {
            throw new RuntimeException('Neuaufbau nur für _ext_test und _ext_dusk erlaubt: '.$this->schema());
        }

        $schema = $this->db->getSchemaBuilder();
        $schema->dropAllViews();
        $schema->dropAllTables();
        $this->createTables();
        $this->seed();
    }

    protected function qualified(string $table): string
    {
        return $this->schema().'.'.$table;
    }

    private function isMariaDb(): bool
    {
        return $this->db->getDriverName() === 'mariadb';
    }

    /** MariaDB speichert datetime ohne Zeitzonenangabe. */
    protected function importTime(string $local): string
    {
        return $this->isMariaDb() ? $local : $local.'+01:00';
    }

    /** MySQL meldet einen UUID-Vorgabewert nicht zurück: SAFT vergibt den Schlüssel selbst. */
    protected function insertPublication(array $row): string
    {
        $id = (string) Str::uuid();
        $this->db->table($this->qualified('publikationen'))->insert(['id' => $id, ...$row]);

        return $id;
    }

    private function createTables(): void
    {
        $schema = $this->db->getSchemaBuilder();
        $q = $this->qualified(...);

        $schema->create($q('organisationen'), function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 200)->unique();
            $table->char('land', 2);
            $table->string('typ', 30);
            $table->date('gegruendet')->nullable();
            $table->string('webseite', 255)->nullable();
            $table->boolean('aktiv')->default(true);
        });

        $schema->create($q('personen'), function (Blueprint $table) use ($q): void {
            $table->bigIncrements('id');
            $table->string('vorname', 100);
            $table->string('nachname', 100);
            $table->string('vollname', 201)->storedAs("concat(vorname, ' ', nachname)")->comment('Berechnet aus Vor- und Nachname');
            $table->string('email', 200)->nullable()->unique();
            $table->string('orcid', 19)->nullable()->unique();
            $table->date('geburtsdatum')->nullable();
            $table->unsignedInteger('arbeitgeber_id')->nullable();
            $table->foreign('arbeitgeber_id')->references('id')->on($q('organisationen'))->nullOnDelete();
            $table->timestamp('erstellt_am')->useCurrent();
            // Binärdaten: vom Table-Connector nicht unterstützt.
            $table->binary('foto')->nullable();
        });

        $schema->create($q('projekte'), function (Blueprint $table) use ($q): void {
            $table->increments('id');
            $table->string('kurzname', 30)->unique();
            $table->string('titel', 300);
            $table->date('beginn');
            $table->date('ende')->nullable();
            $table->decimal('budget', 12, 2)->nullable();
            $table->enum('status', ['geplant', 'laufend', 'abgeschlossen', 'abgebrochen'])->default('geplant');
            $table->unsignedBigInteger('projektleiter_id');
            $table->foreign('projektleiter_id')->references('id')->on($q('personen'));
            $table->unsignedInteger('partner_id')->nullable();
            $table->foreign('partner_id')->references('id')->on($q('organisationen'))->nullOnDelete();
            $table->json('metadaten')->nullable();
            $table->comment('Forschungsprojekte mit Leitung und Partner');
        });

        $schema->create($q('publikationen'), function (Blueprint $table) use ($q): void {
            $table->uuid('id')->default(new Expression('(uuid())'))->primary();
            $table->text('titel');
            $table->smallInteger('jahr');
            $table->string('doi', 100)->nullable()->unique();
            $table->string('typ', 30);
            $table->boolean('open_access')->default(false);
            $table->unsignedInteger('projekt_id')->nullable();
            $table->foreign('projekt_id')->references('id')->on($q('projekte'))->nullOnDelete();
        });

        $schema->create($q('publikation_autoren'), function (Blueprint $table) use ($q): void {
            $table->uuid('publikation_id');
            $table->foreign('publikation_id')->references('id')->on($q('publikationen'))->cascadeOnDelete();
            $table->unsignedBigInteger('person_id');
            $table->foreign('person_id')->references('id')->on($q('personen'));
            $table->smallInteger('reihenfolge');
            $table->primary(['publikation_id', 'person_id']);
        });

        // Natürlicher Schlüssel: wird beim Anlegen eingegeben, nicht von der Datenbank vergeben.
        $schema->create($q('laender'), function (Blueprint $table): void {
            $table->char('code', 2)->primary();
            $table->string('name', 100);
        });

        // Ohne Primärschlüssel.
        $schema->create($q('importprotokoll'), function (Blueprint $table): void {
            $table->dateTime('zeitpunkt');
            $table->string('quelle', 100);
            $table->integer('zeilen');
            $table->text('meldung')->nullable();
        });

        $grammar = $this->db->getQueryGrammar();
        $t = static fn (string $table): string => $grammar->wrapTable($q($table));

        foreach ([
            'alter table '.$t('organisationen')." add constraint organisationen_typ_check check (typ in ('hochschule', 'forschungsinstitut', 'unternehmen', 'behoerde'))",
            'alter table '.$t('projekte').' add constraint projekte_zeitraum_check check (ende is null or ende >= beginn)',
            'alter table '.$t('publikationen').' add constraint publikationen_jahr_check check (jahr between 1900 and 2100)',
            'alter table '.$t('projekte').' add constraint projekte_budget_check check (budget >= 0)',
            // Muster (Regex): nicht in SAFT übersetzbar, nur die Datenbank prüft.
            'alter table '.$t('organisationen').' add constraint organisationen_webseite_check check ('.($this->isMariaDb() ? "webseite regexp '^https?://'" : "regexp_like(webseite, '^https?://')").')',
            'create view '.$t('projekt_uebersicht').' as
                select p.id, p.kurzname, p.titel, p.status, l.vollname as projektleiter, o.name as partner,
                       count(pub.id) as publikationen
                from '.$t('projekte').' p
                join '.$t('personen').' l on l.id = p.projektleiter_id
                left join '.$t('organisationen').' o on o.id = p.partner_id
                left join '.$t('publikationen').' pub on pub.projekt_id = p.id
                group by p.id, p.kurzname, p.titel, p.status, l.vollname, o.name',
        ] as $statement) {
            $this->db->statement($statement);
        }
    }
}
