<?php

declare(strict_types=1);

namespace App\Apps;

use App\DynamicData\Rules\CopySource;
use App\DynamicData\Schema\FieldType;
use App\Models\App;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Schnittstellen zwischen Apps (B-107, Etappe 5): Eine App gibt einzelne Tabellen mit ausgewählten Feldern für alle
 * anderen Apps frei – nur lesend. Andere Apps verweisen darauf mit Verknüpfungsfeldern (Anzeige- und Übernahmefelder nur
 * freigegebene); Summen über Kind-Tabellen einer anderen App gibt es nicht. Solange eine andere App eine Freigabe nutzt, ist sie Vertrag:
 * nicht löschen, deaktivieren, umbenennen, unverträglich ändern, Freigabe zurücknehmen, in eine andere App verschieben.
 */
final class AppInterfaces
{
    /** Liegt die Tabelle in einer anderen App als $appId? Tabellen ohne App (System) gelten nie als fremd. */
    public static function foreign(CustomTable $table, ?int $appId): bool
    {
        return $table->app_id !== null && $appId !== null && $table->app_id !== $appId;
    }

    /** Ist das Feld über die Schnittstelle lesbar? Die ID immer, sonst nur freigegebene Felder freigegebener Tabellen. */
    public static function fieldShared(CustomTable $table, string $field): bool
    {
        if (! $table->is_shared) {
            return false;
        }

        return $field === 'id' || $table->fields->contains(static fn (CustomField $f): bool => $f->name === $field && $f->is_shared && $f->is_active);
    }

    /** Ist die App der Tabelle deaktiviert (für alle ausser dem Super-Admin „nicht verfügbar“)? */
    public static function unavailable(CustomTable $table, ?User $user): bool
    {
        return $table->app_id !== null && ! App::isActiveId($table->app_id) && ! ($user?->isSuperAdmin() ?? false);
    }

    /**
     * Wer aus anderen Apps eine Tabelle (oder ein Feld davon) nutzt: Verknüpfungsfelder, die auf sie zeigen – mit dem
     * Feld als Anzeigefeld oder Quelle von „Übernehmen aus“ –, Tabellenverknüpfungen auf das Feld und Auswertungen mit
     * dem Feld (Etappe 5b).
     *
     * @return list<string> „App › Tabelle.Feld“
     */
    public function usages(CustomTable $table, ?string $field = null): array
    {
        $usages = [];

        foreach ($this->relationsTo($table) as $relation) {
            $owner = $relation->custom_table;

            if (! self::foreign($owner, $table->app_id)) {
                continue;
            }

            $uses = $field === null
                || in_array($field, $this->displayFields($relation), true)
                || $this->copiesFrom($owner, $relation->name, $field);

            if ($uses) {
                $usages[] = (string) ($owner->app?->name ?? '–').' › '.$owner->name.'.'.$relation->name;
            }
        }

        foreach ($this->linksTo($table) as $link) {
            if ($field === null || ($link->targetField?->name ?? 'id') === $field) {
                $usages[] = (string) ($link->sourceTable?->app?->name ?? '–').' › '.$link->sourceTable?->name.' → '.$link->label;
            }
        }

        foreach ($this->reportsUsing($table) as ['report' => $report, 'fields' => $fields]) {
            if ($field === null || in_array($field, $fields, true)) {
                $usages[] = (string) ($report->app?->name ?? '–').' › '.__('apps.interfaces.report', ['name' => $report->name]);
            }
        }

        return array_values(array_unique($usages));
    }

    /**
     * Felder der Tabelle, die andere Apps nutzen (Anzeige- oder Übernahmefeld) – sie lassen sich nicht aus der Freigabe nehmen.
     *
     * @return list<string>
     */
    public function usedFields(CustomTable $table): array
    {
        if (! $table->is_shared) {
            return [];
        }

        return $table->fields->filter(fn (CustomField $f): bool => $this->usages($table, $f->name) !== [])->pluck('name')->values()->all();
    }

    /**
     * Freigaben einer App und wer sie nutzt.
     *
     * @return list<array{table: CustomTable, fields: list<string>, usages: list<string>}>
     */
    public function offeredBy(App $app): array
    {
        return CustomTable::query()->where('app_id', $app->id)->where('is_shared', true)->with('fields')->orderBy('name')->get()
            ->map(fn (CustomTable $table): array => [
                'table' => $table,
                'fields' => $table->fields->filter(static fn (CustomField $f): bool => $f->is_shared)->pluck('name')->values()->all(),
                'usages' => $this->usages($table),
            ])->all();
    }

    /**
     * Freigegebene Tabellen anderer Apps, die eine App nutzt.
     *
     * @return list<string> „App › Tabelle“
     */
    public function usedBy(App $app): array
    {
        $used = [];

        foreach (CustomField::query()->where('type', FieldType::Relation->value)->whereHas('custom_table', static fn ($q) => $q->where('app_id', $app->id))->get() as $relation) {
            $target = CustomTable::query()->where('name', (string) ($relation->optionsArray()['table'] ?? ''))->first();

            if ($target !== null && self::foreign($target, $app->id)) {
                $used[] = (string) ($target->app?->name ?? '–').' › '.$target->label;
            }
        }

        return array_values(array_unique($used));
    }

    /** @return Collection<int, CustomField> Verknüpfungsfelder (aller Tabellen) auf diese Tabelle */
    private function relationsTo(CustomTable $table): Collection
    {
        return CustomField::query()->where('type', FieldType::Relation->value)->where('options->table', $table->name)->with('custom_table.app')->get();
    }

    /** @return Collection<int, ReportLink> Tabellenverknüpfungen anderer Apps auf diese Tabelle */
    private function linksTo(CustomTable $table): Collection
    {
        return ReportLink::query()->where('target_table_id', $table->id)->with(['sourceTable.app', 'targetField'])->get()
            ->filter(static fn (ReportLink $link): bool => $link->sourceTable !== null && self::foreign($link->sourceTable, $table->app_id))
            ->values();
    }

    /**
     * Auswertungen anderer Apps mit Feldern dieser Tabelle.
     *
     * @return list<array{report: Report, fields: list<string>}>
     */
    private function reportsUsing(CustomTable $table): array
    {
        if ($table->app_id === null) {
            return [];
        }

        $names = $table->fields->pluck('name', 'id');
        $found = [];

        foreach (Report::query()->whereNotNull('app_id')->where('app_id', '!=', $table->app_id)->with('app')->get() as $report) {
            $fields = array_values(array_unique(array_filter(array_map(
                static fn (array $spec): ?string => $names[(int) ($spec['field'] ?? 0)] ?? null,
                $report->definitionObject()->fields,
            ))));

            if ($fields !== []) {
                $found[] = ['report' => $report, 'fields' => $fields];
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function displayFields(CustomField $relation): array
    {
        $options = $relation->optionsArray();

        return array_values(array_map('strval', (array) ($options['display_fields'] ?? array_filter([$options['display_field'] ?? null]))));
    }

    /** Übernimmt ein Feld der Tabelle über diese Verknüpfung den Wert dieses Felds („verknüpfung.feld“)? */
    private function copiesFrom(CustomTable $owner, string $relation, string $field): bool
    {
        return $owner->fields->contains(static fn (CustomField $f): bool => ($f->optionsArray()[CopySource::OPTION] ?? null) === $relation.'.'.$field);
    }
}
