<?php

declare(strict_types=1);

namespace App\Configuration;

use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;

/**
 * Verweise einer Installation als Namen statt IDs (und zurück): Tabelle „kunden“, Feld „kunden.firma“, Verknüpfung
 * für Auswertungen „bestellungen.kunde_nr>kunden“, Menüpfad „Daten › Verkauf › Kunden“ (Bezeichnungen in der
 * Standardsprache). Die Nachschlagetabellen werden einmal geladen; nach Änderungen refresh() aufrufen.
 */
final class References
{
    /** @var array<int, string>|null */
    private ?array $tableNames = null;

    /** @var array<int, string>|null */
    private ?array $fieldNames = null;

    /** @var array<int, string>|null */
    private ?array $linkNames = null;

    /** @var array<int, string>|null */
    private ?array $menuPaths = null;

    public function refresh(): void
    {
        $this->tableNames = $this->fieldNames = $this->linkNames = $this->menuPaths = null;
    }

    public function table(int $id): ?string
    {
        return $this->tableNames()[$id] ?? null;
    }

    public function tableId(string $name): ?int
    {
        return ($id = array_search($name, $this->tableNames(), true)) === false ? null : (int) $id;
    }

    public function field(int $id): ?string
    {
        return $this->fieldNames()[$id] ?? null;
    }

    public function fieldId(string $reference): ?int
    {
        return ($id = array_search($reference, $this->fieldNames(), true)) === false ? null : (int) $id;
    }

    public function link(int $id): ?string
    {
        return $this->linkNames()[$id] ?? null;
    }

    public function linkId(string $reference): ?int
    {
        return ($id = array_search($reference, $this->linkNames(), true)) === false ? null : (int) $id;
    }

    /** Verknüpfung für Auswertungen als „quelltabelle.feld>zieltabelle“ (eindeutig je Quelle und Ziel). */
    public function linkReference(ReportLink $link): ?string
    {
        $source = $this->field((int) $link->source_field_id);
        $target = $this->table((int) $link->target_table_id);

        return $source === null || $target === null ? null : $source.'>'.$target;
    }

    public function menuPath(int $id): ?string
    {
        return $this->menuPaths()[$id] ?? null;
    }

    public function menuId(string $path): ?int
    {
        return ($id = array_search($path, $this->menuPaths(), true)) === false ? null : (int) $id;
    }

    public function reportId(string $name): ?int
    {
        $id = Report::query()->where('name', $name)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** Bezeichnung in der Standardsprache (Menüpfade, Abgleich). */
    public static function defaultLabel(mixed $translations): string
    {
        $translations = (array) $translations;

        return (string) ($translations[config('locales.default')] ?? reset($translations) ?: '');
    }

    /** @return array<int, string> */
    private function tableNames(): array
    {
        return $this->tableNames ??= CustomTable::query()->pluck('name', 'id')->map(static fn (mixed $n): string => (string) $n)->all();
    }

    /** @return array<int, string> */
    private function fieldNames(): array
    {
        if ($this->fieldNames === null) {
            $tables = $this->tableNames();
            $this->fieldNames = [];

            foreach (CustomField::query()->get(['id', 'custom_table_id', 'name']) as $field) {
                $this->fieldNames[(int) $field->id] = ($tables[(int) $field->custom_table_id] ?? '?').'.'.$field->name;
            }
        }

        return $this->fieldNames;
    }

    /** @return array<int, string> */
    private function linkNames(): array
    {
        if ($this->linkNames === null) {
            $this->linkNames = [];

            foreach (ReportLink::query()->get() as $link) {
                if (($reference = $this->linkReference($link)) !== null) {
                    $this->linkNames[(int) $link->id] = $reference;
                }
            }
        }

        return $this->linkNames;
    }

    /** @return array<int, string> */
    private function menuPaths(): array
    {
        if ($this->menuPaths === null) {
            $menus = Menu::query()->get(['id', 'parent_id', 'label'])->keyBy('id');
            $this->menuPaths = [];

            foreach ($menus as $menu) {
                $labels = [];

                for ($current = $menu, $depth = 0; $current !== null && $depth < 10; $current = $menus->get((int) $current->parent_id), $depth++) {
                    array_unshift($labels, self::defaultLabel($current->getTranslations('label')));
                }

                $this->menuPaths[(int) $menu->id] = implode(' › ', $labels);
            }
        }

        return $this->menuPaths;
    }
}
