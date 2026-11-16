<?php

declare(strict_types=1);

namespace App\DynamicData\Usages;

/** Eine Stelle, an der eine Tabelle verwendet wird (Reiter „Verwendet in“ im Table-Designer). */
final readonly class TableUsage
{
    public const string PLUGIN = 'plugin';

    public const string REPORT = 'report';

    public const string REPORT_LINK = 'report_link';

    public const string MENU = 'menu';

    public const string RELATION = 'relation';

    public const string FORMULA = 'formula';

    public const string COPY = 'copy';

    public const string ROLE = 'role';

    public const string TASK = 'task';

    /** Reihenfolge der Bereiche in Liste und Warnung. */
    public const array AREAS = [self::PLUGIN, self::REPORT, self::REPORT_LINK, self::MENU, self::RELATION, self::FORMULA, self::COPY, self::ROLE, self::TASK];

    public function __construct(
        public string $area,
        public string $name,
        public string $detail,
        public ?string $url = null,
    ) {}

    /** @return array{key: string, area: string, name: string, detail: string, url: ?string} Zeile für die Tabelle */
    public function toRow(int $index): array
    {
        return ['key' => $this->area.'-'.$index, 'area' => $this->area, 'name' => $this->name, 'detail' => $this->detail, 'url' => $this->url];
    }
}
