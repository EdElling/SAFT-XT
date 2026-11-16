<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * „Fremde“ Forschungsdatenbank für den Table-Connector: Demo (SAFT2_ext) und Tests
 * (SAFT2_ext_test, SAFT2_ext_dusk).
 *
 * Die Tabellen decken bewusst die Sonderfälle ab, die der Table-Connector erkennen muss:
 * Identity- und Sequenz-Schlüssel, UUID-Schlüssel, berechnete Spalte, zusammengesetzter
 * Primärschlüssel, Tabelle ohne Primärschlüssel, View, CHECK-Constraints und jsonb.
 * Einige Anweisungen sind deshalb bewusst PostgreSQL-spezifisch; das ist Test- und Democode.
 *
 * Schutz: Gearbeitet wird nur mit Datenbanken, deren Name auf _ext, _ext_test oder _ext_dusk
 * endet; Leeren und Neuaufbau nur bei _ext_test und _ext_dusk.
 */
final class ExternalResearchDatabase
{
    use ResearchDemoData;

    public const string SCHEMA = 'forschung';

    private const string CONNECTION = 'ext_fixture';

    private function __construct(private readonly Connection $db) {}

    /** Verbindung zur genannten Datenbank auf demselben Server wie die Anwendung. */
    public static function on(string $database): self
    {
        if (preg_match('/_ext(_test|_dusk)?$/', $database) !== 1) {
            throw new RuntimeException("Externe Demo-/Testdatenbank abgelehnt: {$database} (Name muss auf _ext, _ext_test oder _ext_dusk enden).");
        }

        DB::purge(self::CONNECTION);
        config(['database.connections.'.self::CONNECTION => [
            ...config('database.connections.pgsql'),
            'database' => $database,
            'search_path' => 'public',
        ]]);

        $db = DB::connection(self::CONNECTION);

        if ($db->getDatabaseName() !== $database) {
            throw new RuntimeException('Externe Datenbank: unerwartetes Ziel '.$db->getDatabaseName());
        }

        return new self($db);
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    public function exists(): bool
    {
        return $this->db->getSchemaBuilder()->hasTable(self::SCHEMA.'.organisationen');
    }

    /** Aufbau entspricht dieser Klasse (für Testdatenbanken nach Änderungen am Aufbau). */
    public function isCurrent(): bool
    {
        return $this->exists()
            && $this->db->getSchemaBuilder()->hasTable(self::SCHEMA.'.laender')
            && $this->db->table('information_schema.check_constraints')->where('constraint_schema', self::SCHEMA)->where('constraint_name', 'organisationen_webseite_check')->exists();
    }

    /** Legt Schema, Tabellen und Daten an, falls sie fehlen; löscht nie etwas. */
    public function install(): bool
    {
        if ($this->exists()) {
            return false;
        }

        $this->build();

        return true;
    }

    /** Nur für Testdatenbanken: alles verwerfen und neu aufbauen. */
    public function reset(): void
    {
        if (preg_match('/_ext_(test|dusk)$/', $this->db->getDatabaseName()) !== 1) {
            throw new RuntimeException('Neuaufbau nur für _ext_test und _ext_dusk erlaubt: '.$this->db->getDatabaseName());
        }

        $this->db->statement('drop schema if exists '.self::SCHEMA.' cascade');
        $this->db->getSchemaBuilder()->dropIfExists('public.importprotokoll');
        $this->build();
    }

    private function build(): void
    {
        $this->db->transaction(function (): void {
            $this->createTables();
            $this->seed();
        });
    }

    private function createTables(): void
    {
        $schema = $this->db->getSchemaBuilder();
        $this->db->statement('create schema if not exists '.self::SCHEMA);

        $schema->create(self::SCHEMA.'.organisationen', function (Blueprint $table): void {
            $table->integer('id')->generatedAs()->always()->primary();
            $table->string('name', 200)->unique();
            $table->char('land', 2);
            $table->string('typ', 30);
            $table->date('gegruendet')->nullable();
            $table->string('webseite', 255)->nullable();
            $table->boolean('aktiv')->default(true);
        });

        $schema->create(self::SCHEMA.'.personen', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('vorname', 100);
            $table->string('nachname', 100);
            $table->string('vollname', 201)->storedAs("vorname || ' ' || nachname");
            $table->string('email', 200)->nullable()->unique();
            $table->string('orcid', 19)->nullable()->unique();
            $table->date('geburtsdatum')->nullable();
            $table->foreignId('arbeitgeber_id')->nullable()->constrained(self::SCHEMA.'.organisationen')->nullOnDelete();
            $table->timestampTz('erstellt_am')->default(new Expression('now()'));
            // Binärdaten: vom Table-Connector nicht unterstützt.
            $table->binary('foto')->nullable();
        });

        $schema->create(self::SCHEMA.'.projekte', function (Blueprint $table): void {
            $table->integer('id')->generatedAs()->primary();
            $table->string('kurzname', 30)->unique();
            $table->string('titel', 300);
            $table->date('beginn');
            $table->date('ende')->nullable();
            $table->decimal('budget', 12, 2)->nullable();
            $table->string('status', 20)->default('geplant');
            $table->foreignId('projektleiter_id')->constrained(self::SCHEMA.'.personen');
            $table->foreignId('partner_id')->nullable()->constrained(self::SCHEMA.'.organisationen')->nullOnDelete();
            $table->jsonb('metadaten')->nullable();
        });

        $schema->create(self::SCHEMA.'.publikationen', function (Blueprint $table): void {
            $table->uuid('id')->default(new Expression('gen_random_uuid()'))->primary();
            $table->text('titel');
            $table->smallInteger('jahr');
            $table->string('doi', 100)->nullable()->unique();
            $table->string('typ', 30);
            $table->boolean('open_access')->default(false);
            $table->foreignId('projekt_id')->nullable()->constrained(self::SCHEMA.'.projekte')->nullOnDelete();
        });

        $schema->create(self::SCHEMA.'.publikation_autoren', function (Blueprint $table): void {
            $table->foreignUuid('publikation_id')->constrained(self::SCHEMA.'.publikationen')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained(self::SCHEMA.'.personen');
            $table->smallInteger('reihenfolge');
            $table->primary(['publikation_id', 'person_id']);
        });

        // Natürlicher Schlüssel: wird beim Anlegen eingegeben, nicht von der Datenbank vergeben.
        $schema->create(self::SCHEMA.'.laender', function (Blueprint $table): void {
            $table->char('code', 2)->primary();
            $table->string('name', 100);
        });

        $schema->create('public.importprotokoll', function (Blueprint $table): void {
            $table->timestampTz('zeitpunkt');
            $table->string('quelle', 100);
            $table->integer('zeilen');
            $table->text('meldung')->nullable();
        });

        foreach ([
            "alter table forschung.organisationen add constraint organisationen_typ_check check (typ in ('hochschule', 'forschungsinstitut', 'unternehmen', 'behoerde'))",
            "alter table forschung.projekte add constraint projekte_status_check check (status in ('geplant', 'laufend', 'abgeschlossen', 'abgebrochen'))",
            'alter table forschung.projekte add constraint projekte_zeitraum_check check (ende is null or ende >= beginn)',
            'alter table forschung.publikationen add constraint publikationen_jahr_check check (jahr between 1900 and 2100)',
            'alter table forschung.projekte add constraint projekte_budget_check check (budget >= 0)',
            // Muster (Regex): nicht in SAFT übersetzbar, nur die Datenbank prüft.
            "alter table forschung.organisationen add constraint organisationen_webseite_check check (webseite ~ '^https?://')",
            "comment on table forschung.projekte is 'Forschungsprojekte mit Leitung und Partner'",
            "comment on column forschung.personen.vollname is 'Berechnet aus Vor- und Nachname'",
            <<<'SQL'
                create view forschung.projekt_uebersicht as
                select p.id, p.kurzname, p.titel, p.status, l.vollname as projektleiter, o.name as partner,
                       count(pub.id) as publikationen
                from forschung.projekte p
                join forschung.personen l on l.id = p.projektleiter_id
                left join forschung.organisationen o on o.id = p.partner_id
                left join forschung.publikationen pub on pub.projekt_id = p.id
                group by p.id, p.kurzname, p.titel, p.status, l.vollname, o.name
            SQL,
        ] as $statement) {
            $this->db->statement($statement);
        }
    }

    protected function qualified(string $table): string
    {
        return ($table === 'importprotokoll' ? 'public' : self::SCHEMA).'.'.$table;
    }
}
