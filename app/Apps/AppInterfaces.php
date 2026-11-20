<?php

declare(strict_types=1);

namespace App\Apps;

use App\DynamicData\Rules\CopySource;
use App\DynamicData\Schema\FieldType;
use App\Models\App;
use App\Models\CustomField;
use App\Models\CustomTable;
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

    /**
     * Wer aus anderen Apps eine Tabelle (oder ein Feld davon) nutzt: Verknüpfungsfelder, die auf sie zeigen – mit dem
     * Feld als Anzeigefeld oder Quelle von „Übernehmen aus“.
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
