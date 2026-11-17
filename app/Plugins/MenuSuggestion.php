<?php

declare(strict_types=1);

namespace App\Plugins;

/**
 * Vorschlag eines Plugins für einen Menüeintrag. Die Administration übernimmt ihn auf der Seite „Plugins“;
 * danach ist er ein gewöhnlicher Menüeintrag (verschieben, umbenennen, Rollen zuweisen).
 */
final readonly class MenuSuggestion
{
    /**
     * @param  string  $route  Name der Route, z. B. „filament.admin.pages.lager-umbuchung“
     * @param  array<string, string>  $label  Bezeichnung je Sprache (de, en, fr, it)
     * @param  array<string, string>|null  $group  Menügruppe je Sprache; gefunden über die deutsche Bezeichnung,
     *                                             sonst neu angelegt; null = oberste Ebene
     * @param  string|null  $permission  vollständiger Name des Rechts, das den Eintrag sichtbar macht
     * @param  array<string, string>|null  $subgroup  Untermenü innerhalb der Gruppe je Sprache (seit API 1.7), z. B.
     *                                                „Lager führen“; gefunden über die deutsche Bezeichnung, sonst neu
     *                                                angelegt; null = direkt in der Gruppe. Nur zusammen mit $group.
     */
    public function __construct(
        public string $route,
        public array $label,
        public ?string $icon = null,
        public ?string $permission = null,
        public ?array $group = null,
        public int $sortOrder = 100,
        public ?array $subgroup = null,
    ) {}
}
