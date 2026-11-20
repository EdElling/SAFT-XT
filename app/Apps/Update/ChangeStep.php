<?php

declare(strict_types=1);

namespace App\Apps\Update;

/**
 * Ein Schritt des Änderungsplans (B-107, Etappe 6): ein Objekt der App (über seinen stabilen Schlüssel) wird angelegt,
 * geändert, deaktiviert oder gelöscht. Ein Konflikt (lokal und im Paket verschieden geändert) ist ein eigener Schritt
 * neben den übrigen Änderungen desselben Objekts; ohne Auswahl bleibt der lokale Stand („behalten“).
 */
final readonly class ChangeStep
{
    public const string CREATE = 'create';

    public const string UPDATE = 'update';

    public const string DEACTIVATE = 'deactivate';

    public const string DELETE = 'delete';

    public const string SAFE = 'safe';

    public const string RISKY = 'risky';

    public const string DESTRUCTIVE = 'destructive';

    /** Objektarten, deren Schritte ausgeführt werden (die übrigen zeigt die Vorschau nur an). */
    public const array EXECUTABLE = ['app', 'table', 'field', 'external_table', 'report_link', 'report', 'menu', 'role', 'task', 'manual'];

    /**
     * @param  string  $kind  app, table, field, external_table, report_link, report, menu, role, task, manual
     * @param  list<array{attr: string, from: mixed, to: mixed}>  $changes  aktueller → neuer Wert je Eigenschaft
     * @param  list<string>  $notes  Gründe der Einstufung (z. B. „3 Werte vorhanden“)
     * @param  array<string, mixed>  $target  Objekt aus dem Paket (Anlegen, Ändern)
     * @param  string|null  $table  Schlüssel der Tabelle (bei Feldern)
     * @param  string|null  $blocked  Grund, warum der Schritt nicht ausführbar ist (z. B. Schnittstelle genutzt)
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $action,
        public string $key,
        public string $label,
        public array $changes = [],
        public string $level = self::SAFE,
        public bool $conflict = false,
        public array $notes = [],
        public array $target = [],
        public ?string $table = null,
        public ?string $blocked = null,
    ) {}

    /** Führt Etappe 6a diesen Schritt aus? */
    public function executable(): bool
    {
        return in_array($this->kind, self::EXECUTABLE, true);
    }

    /** Wählbar: ausführbar und nicht gesperrt. */
    public function selectable(): bool
    {
        return $this->executable() && $this->blocked === null;
    }

    /** Vorauswahl: sichere und heikle Schritte ohne Konflikt; zerstörende und Konflikte nur auf ausdrückliche Wahl. */
    public function preselected(): bool
    {
        return $this->selectable() && ! $this->conflict && $this->level !== self::DESTRUCTIVE;
    }

    /** @param  array<string, mixed>  $data */
    public function with(array $data): self
    {
        return new self(...[...get_object_vars($this), ...$data]);
    }
}
