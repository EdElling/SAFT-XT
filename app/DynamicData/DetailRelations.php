<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\ReportLink;
use Illuminate\Support\Collection;

/**
 * 1:n-Beziehungen einer Tabelle: Verknüpfungsfelder anderer (oder derselben) Tabellen, die auf sie
 * zeigen, und Tabellenverknüpfungen mit ihr als Ziel. Auswahl je Feld bzw. Verknüpfung, da eine
 * Kindtabelle mehrere Verknüpfungen zur selben Tabelle haben kann.
 */
final class DetailRelations
{
    /**
     * Wählbare Details: aktive Verknüpfungsfelder in aktiven Tabellen und gültige Tabellenverknüpfungen,
     * die auf diese Tabelle zeigen.
     *
     * @return Collection<int, DetailSource>
     */
    public static function candidates(string $parentTable): Collection
    {
        return self::fieldCandidates($parentTable)
            ->map(fn (CustomField $f): DetailSource => DetailSource::ofField($f))
            ->concat(self::linkCandidates($parentTable))
            ->values();
    }

    /**
     * Nur Verknüpfungsfelder (Fremdschlüssel), z. B. für die Massenverknüpfung.
     *
     * @return Collection<int, CustomField>
     */
    public static function fieldCandidates(string $parentTable): Collection
    {
        return self::referencing($parentTable)
            ->filter(fn (CustomField $f): bool => $f->is_active && $f->custom_table->is_active)
            ->values();
    }

    /**
     * Tabellenverknüpfungen auf diese Tabelle: Quelltabelle und -feld aktiv, Zielfeld aktiv bzw. id.
     *
     * @return Collection<int, DetailSource>
     */
    public static function linkCandidates(string $parentTable): Collection
    {
        return ReportLink::query()
            ->with(['sourceTable', 'sourceField', 'targetTable', 'targetField'])
            ->whereHas('targetTable', fn ($q) => $q->where('name', $parentTable))
            ->orderBy('id')
            ->get()
            ->filter(fn (ReportLink $link): bool => $link->sourceTable?->is_active === true
                && $link->sourceField?->is_active === true
                && ($link->target_field_id === null ? ! $link->targetTable->isExternal() : $link->targetField?->is_active === true))
            ->map(fn (ReportLink $link): DetailSource => DetailSource::ofLink($link))
            ->values();
    }

    /**
     * Alle Verknüpfungsfelder auf diese Tabelle (auch inaktive) – z. B. für die Löschsperre.
     * Tabellenverknüpfungen sperren das Löschen bewusst nicht (keine Fremdschlüssel).
     *
     * @return Collection<int, CustomField>
     */
    public static function referencing(string $parentTable): Collection
    {
        // Bewusst ohne Zwischenspeicher: Verknüpfungen können sich im selben Request ändern.
        return CustomField::query()
            ->with('custom_table')
            ->where('type', FieldType::Relation->value)
            ->where('options->table', $parentTable)
            ->orderBy('custom_table_id')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Konfigurierte Details, die (noch) gültig sind – in Reihenfolge der Konfiguration.
     *
     * @param  list<int>  $ids  Feld-IDs bzw. negative IDs von Tabellenverknüpfungen
     * @return Collection<int, DetailSource>
     */
    public static function resolve(string $parentTable, array $ids): Collection
    {
        $candidates = self::candidates($parentTable)->keyBy('id');

        return collect($ids)
            ->map(fn (mixed $id): ?DetailSource => $candidates->get((int) $id))
            ->filter()
            ->values();
    }

    public static function label(CustomField|DetailSource $detail): string
    {
        return ($detail instanceof DetailSource ? $detail : DetailSource::ofField($detail))->label();
    }

    /**
     * @return array<int, string> Kennung => Beschriftung
     */
    public static function options(string $parentTable): array
    {
        return self::candidates($parentTable)
            ->mapWithKeys(fn (DetailSource $d): array => [$d->id => $d->label()])
            ->all();
    }

    /**
     * Für die Tabelle konfigurierte, gültige Details (Table-Designer, Table-Connector).
     *
     * @return Collection<int, DetailSource>
     */
    public static function configuredFor(CustomTable $table): Collection
    {
        return self::resolve($table->name, array_map('intval', (array) ($table->detail_field_ids ?? [])));
    }
}
