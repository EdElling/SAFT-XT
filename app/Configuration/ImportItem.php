<?php

declare(strict_types=1);

namespace App\Configuration;

/** Ein Eintrag der Vorschau beim Einspielen: was mit einer Tabelle, Auswertung, … des Pakets geschieht. */
final readonly class ImportItem
{
    public const string NEW = 'new';

    public const string IDENTICAL = 'identical';

    public const string DIFFERENT = 'different';

    public const string MISSING = 'missing';

    public const string TABLE = 'table';

    public const string REPORT_LINK = 'report_link';

    public const string REPORT = 'report';

    public const string MENU = 'menu';

    public const string ROLE = 'role';

    /** Reihenfolge der Bereiche (Vorschau und Einspielen). */
    public const array AREAS = [self::TABLE, self::REPORT_LINK, self::REPORT, self::MENU, self::ROLE];

    /**
     * @param  string  $key  Kennung im Paket (Tabellenname, Auswertungsname, Menüpfad, …)
     * @param  list<string>  $notes  Gründe (fehlende Voraussetzungen) und Hinweise (z. B. Recht übergangen)
     */
    public function __construct(
        public string $area,
        public string $key,
        public string $label,
        public string $status,
        public array $notes = [],
    ) {}

    /** @return array{key: string, area: string, name: string, status: string, notes: string} */
    public function toRow(): array
    {
        return ['key' => $this->area.':'.$this->key, 'area' => $this->area, 'name' => $this->label, 'status' => $this->status, 'notes' => implode(' · ', $this->notes)];
    }
}
