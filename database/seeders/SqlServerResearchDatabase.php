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
 * SQL-Server-Fassung der Forschungsdatenbank für den Table-Connector (Demo und Tests). Wie bei
 * PostgreSQL liegen die Tabellen im Schema „forschung“ einer vorhandenen Datenbank (MSSQL_*); der
 * Login braucht dort db_owner, darf aber keine Datenbanken anlegen müssen.
 *
 * Sonderfälle, soweit SQL Server sie kennt: IDENTITY, UUID-Schlüssel mit DEFAULT newid(), berechnete
 * Spalte, zusammengesetzter Primärschlüssel, natürlicher Schlüssel, Tabelle ohne Primärschlüssel,
 * View, CHECK-Regeln (auch als Werteliste), Zeitstempel mit Zeitzone, Binärdaten. Eindeutige Spalten
 * mit NULL-Werten brauchen gefilterte Indizes (ein normaler eindeutiger Index erlaubt nur ein NULL).
 *
 * Schutz wie bei ExternalResearchDatabase: nur Datenbanken auf _ext, _ext_test oder _ext_dusk;
 * Leeren und Neuaufbau nur bei _ext_test und _ext_dusk.
 */
final class SqlServerResearchDatabase
{
    use ResearchDemoData;

    public const string SCHEMA = 'forschung';

    private const string CONNECTION = 'ext_fixture_sqlsrv';

    /** In umgekehrter Reihenfolge der Abhängigkeiten (zum Löschen). */
    private const array TABLES = ['publikation_autoren', 'publikationen', 'projekte', 'personen', 'organisationen', 'laender', 'importprotokoll'];

    private function __construct(private readonly Connection $db) {}

    /** Verbindung zur genannten, vorhandenen Datenbank auf dem SQL Server der Verbindung sqlsrv (MSSQL_*). */
    public static function on(string $database): self
    {
        if (preg_match('/_ext(_test|_dusk)?$/', $database) !== 1) {
            throw new RuntimeException("Externe Demo-/Testdatenbank abgelehnt: {$database} (Name muss auf _ext, _ext_test oder _ext_dusk enden).");
        }

        if (! in_array('sqlsrv', \PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Die PHP-Erweiterung pdo_sqlsrv ist nicht installiert.');
        }

        DB::purge(self::CONNECTION);
        config(['database.connections.'.self::CONNECTION => [...config('database.connections.sqlsrv'), 'database' => $database]]);
        $db = DB::connection(self::CONNECTION);

        if ($db->scalar('select db_name()') !== $database) {
            throw new RuntimeException('Externe Datenbank: unerwartetes Ziel '.$db->scalar('select db_name()'));
        }

        return new self($db);
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    public function database(): string
    {
        return (string) $this->db->getDatabaseName();
    }

    public function schema(): string
    {
        return self::SCHEMA;
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

    /** Legt Schema, Tabellen und Daten an, falls sie fehlen; löscht nie etwas. */
    public function install(): bool
    {
        if ($this->exists()) {
            return false;
        }

        $this->createTables();
        $this->seed();

        return true;
    }

    /** Nur für Testdatenbanken: Schema „forschung“ verwerfen und neu aufbauen. */
    public function reset(): void
    {
        if (preg_match('/_ext_(test|dusk)$/', $this->database()) !== 1) {
            throw new RuntimeException('Neuaufbau nur für _ext_test und _ext_dusk erlaubt: '.$this->database());
        }

        $schema = $this->db->getSchemaBuilder();
        $this->db->statement('drop view if exists '.$this->wrap('projekt_uebersicht'));

        foreach (self::TABLES as $table) {
            $schema->dropIfExists($this->qualified($table));
        }

        $this->createTables();
        $this->seed();
    }

    protected function qualified(string $table): string
    {
        return self::SCHEMA.'.'.$table;
    }

    /** SQL Server meldet einen UUID-Vorgabewert nicht zurück: SAFT vergibt den Schlüssel selbst. */
    protected function insertPublication(array $row): string
    {
        $id = (string) Str::uuid();
        $this->db->table($this->qualified('publikationen'))->insert(['id' => $id, ...$row]);

        return $id;
    }

    private function wrap(string $table): string
    {
        return $this->db->getQueryGrammar()->wrapTable($this->qualified($table));
    }

    private function createTables(): void
    {
        // CREATE SCHEMA muss allein im Batch stehen.
        if ((int) $this->db->scalar('select count(*) from sys.schemas where name = ?', [self::SCHEMA]) === 0) {
            $this->db->statement('create schema '.self::SCHEMA);
        }

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
            $table->computed('vollname', "vorname + ' ' + nachname")->persisted()->comment('Berechnet aus Vor- und Nachname');
            $table->string('email', 200)->nullable();
            $table->string('orcid', 19)->nullable();
            $table->date('geburtsdatum')->nullable();
            $table->unsignedInteger('arbeitgeber_id')->nullable();
            $table->foreign('arbeitgeber_id')->references('id')->on($q('organisationen'))->nullOnDelete();
            $table->dateTime('erstellt_am')->useCurrent();
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
            // SQL Server kennt kein enum: Laravel legt Text mit CHECK-Werteliste an.
            $table->enum('status', ['geplant', 'laufend', 'abgeschlossen', 'abgebrochen'])->default('geplant');
            $table->unsignedBigInteger('projektleiter_id');
            $table->foreign('projektleiter_id')->references('id')->on($q('personen'));
            $table->unsignedInteger('partner_id')->nullable();
            $table->foreign('partner_id')->references('id')->on($q('organisationen'))->nullOnDelete();
            $table->json('metadaten')->nullable();
            $table->comment('Forschungsprojekte mit Leitung und Partner');
        });

        $schema->create($q('publikationen'), function (Blueprint $table) use ($q): void {
            $table->uuid('id')->default(new Expression('newid()'))->primary();
            $table->text('titel');
            $table->smallInteger('jahr');
            $table->string('doi', 100)->nullable();
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

        // Ohne Primärschlüssel; Zeitstempel mit Zeitzone (datetimeoffset, nur als Text).
        $schema->create($q('importprotokoll'), function (Blueprint $table): void {
            $table->dateTimeTz('zeitpunkt');
            $table->string('quelle', 100);
            $table->integer('zeilen');
            $table->text('meldung')->nullable();
        });

        $t = $this->wrap(...);

        foreach ([
            // Eindeutig, aber mit mehreren NULL-Werten: gefilterte Indizes.
            'create unique index personen_email_unique on '.$t('personen').' (email) where email is not null',
            'create unique index personen_orcid_unique on '.$t('personen').' (orcid) where orcid is not null',
            'create unique index publikationen_doi_unique on '.$t('publikationen').' (doi) where doi is not null',
            'alter table '.$t('organisationen')." add constraint organisationen_typ_check check (typ in ('hochschule', 'forschungsinstitut', 'unternehmen', 'behoerde'))",
            'alter table '.$t('projekte').' add constraint projekte_zeitraum_check check (ende is null or ende >= beginn)',
            'alter table '.$t('publikationen').' add constraint publikationen_jahr_check check (jahr between 1900 and 2100)',
            'alter table '.$t('projekte').' add constraint projekte_budget_check check (budget >= 0)',
            // Muster: nicht in SAFT übersetzbar, nur die Datenbank prüft.
            'alter table '.$t('organisationen')." add constraint organisationen_webseite_check check (webseite like 'http%://%')",
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
