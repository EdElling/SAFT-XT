<?php

declare(strict_types=1);

namespace App\Apps\Update;

use App\Apps\Definition\References;
use App\Database\DatabaseBackup;
use App\DynamicData\Rules\CopySource;
use App\DynamicData\Rules\SequenceSuggester;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Models\App;
use App\Models\AppBaseline;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\User;
use App\Plugins\Context\SchemaExporter;
use App\Plugins\Context\SchemaImporter;
use App\Services\DynamicSchemaService;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Führt die gewählten Schritte eines Änderungsplans aus (B-107, Etappe 6a: App, Tabellen und Felder). Vorher eine
 * Sicherung; jeder Schritt über die Operationen des Table-Designers mit ihren Schutzregeln. Reihenfolge: App, neue
 * Tabellen, neue Felder (einfach, Verknüpfungen, Übernahmen, berechnet), Änderungen an Feldern und Tabellen, Freigaben,
 * Deaktivieren, zuletzt Löschen. Scheitert ein Schritt, bricht das Aktualisieren ab und meldet, was schon angewendet
 * ist (Schemaänderungen lassen sich nicht überall zurückrollen); ein neuer Plan zeigt dann nur noch den Rest. Nach
 * Erfolg ist das Paket der neue Ausgangsstand.
 */
final class ChangeExecutor
{
    /** @var list<string> */
    private array $done = [];

    public function __construct(
        private readonly DynamicSchemaService $service,
        private readonly SchemaImporter $importer,
        private readonly References $refs,
        private readonly DatabaseBackup $backup,
    ) {}

    /**
     * @param  list<string>  $selected  IDs der gewählten Schritte
     * @return array{steps: list<string>, backup: string}
     *
     * @throws BusinessRuleException
     */
    public function apply(App $app, ChangePlan $plan, array $selected, bool $confirmDestructive, User $user): array
    {
        if (! $plan->canApply()) {
            throw new BusinessRuleException(implode(' ', $plan->blockers));
        }

        $steps = array_values(array_filter($plan->steps, static fn (ChangeStep $s): bool => $s->selectable() && in_array($s->id, $selected, true)));

        if (! $confirmDestructive && collect($steps)->contains(static fn (ChangeStep $s): bool => $s->level === ChangeStep::DESTRUCTIVE)) {
            throw new BusinessRuleException(__('apps.update.errors.confirm'));
        }

        $dump = $this->backup->create();
        $this->backup->backupFiles($dump);
        $this->done = [];

        try {
            $this->run($app, $steps);
        } catch (Throwable $e) {
            activity('apps')->causedBy($user)->performedOn($app)->event('update_partial')
                ->withProperties(['version' => $plan->target['app']['version'] ?? null, 'done' => $this->done, 'error' => $e->getMessage(), 'backup' => basename($dump)])
                ->log(__('apps.update.log_partial', ['name' => (string) $app->name]));

            throw new BusinessRuleException(__('apps.update.errors.partial', [
                'error' => $e->getMessage(),
                'count' => count($this->done),
                'backup' => basename($dump),
            ]), previous: $e);
        }

        $version = $plan->target['app']['version'] ?? null;
        $app->forceFill(['version' => $version])->save();
        AppBaseline::query()->create([
            'app_id' => $app->id,
            'kind' => AppBaseline::UPDATED,
            'version' => $version,
            'definition' => $plan->target,
            'installed_by' => $user->id,
        ]);

        activity('apps')->causedBy($user)->performedOn($app)->event('updated')
            ->withProperties(['from' => $plan->fromVersion, 'version' => $version, 'steps' => $this->done, 'backup' => basename($dump)])
            ->log(__('apps.update.log', ['name' => (string) $app->name, 'version' => (string) ($version ?? '–')]));

        return ['steps' => $this->done, 'backup' => basename($dump)];
    }

    /** @param  list<ChangeStep>  $steps */
    private function run(App $app, array $steps): void
    {
        $of = static fn (string $kind, string $action): array => array_values(array_filter($steps, static fn (ChangeStep $s): bool => $s->kind === $kind && $s->action === $action));

        // 1. App
        foreach ($of('app', ChangeStep::UPDATE) as $step) {
            $app->fill(self::values($step->changes))->save();
            $this->done[] = $step->label;
        }

        // 2. Neue Tabellen samt Feldern (wie beim Installieren).
        $this->createTables($app, $of('table', ChangeStep::CREATE));

        // 3. Neue Felder in bestehenden Tabellen: einfache, Verknüpfungen, Übernahmen und „Zählen je“, berechnete.
        $this->createFields($of('field', ChangeStep::CREATE));

        // 4. Felder ändern (Paket- und gewählte Konfliktänderungen zusammen).
        foreach ($this->merged($of('field', ChangeStep::UPDATE)) as $step) {
            $this->service->updateField($this->field($step->key), array_diff_key(self::values($step->changes), ['options' => true]) + self::options($step->changes));
            $this->done[] = $step->label;
        }

        // 5. Tabellen ändern, dann Freigaben.
        foreach ($this->merged($of('table', ChangeStep::UPDATE)) as $step) {
            $table = $this->table($step->key);
            $values = self::values($step->changes);
            $data = Arr::only($values, ['label', 'description', 'icon', 'is_active', 'export_enabled', 'import_enabled']);

            if (array_key_exists('rules', $values)) {
                $data['validation_rules'] = (array) $values['rules'];
            }

            if (array_key_exists('details', $values)) {
                $this->refs->refresh();
                $data['detail_field_ids'] = array_values(array_filter(array_map(fn (string $ref): ?int => $this->refs->fieldId($ref), (array) $values['details'])));
            }

            if ($data !== []) {
                $this->service->updateTable($table, $data);
            }

            if (array_key_exists('is_shared', $values) || array_key_exists('shared_fields', $values)) {
                $shared = collect((array) ($step->target['fields'] ?? []))->filter(static fn (array $f): bool => ! empty($f['is_shared']))->pluck('name')->all();
                $this->service->updateInterface($table->refresh(), (bool) ($step->target['is_shared'] ?? false), $shared);
            }

            $this->done[] = $step->label;
        }

        // 6. Deaktivieren (Daten bleiben), 7. Löschen – Felder vor Tabellen.
        foreach ([ChangeStep::DEACTIVATE, ChangeStep::DELETE] as $action) {
            foreach ([...$of('field', $action), ...$of('table', $action)] as $step) {
                $step->kind === 'field' ? $this->removeField($step) : $this->removeTable($step);
                $this->done[] = $step->label;
            }
        }
    }

    /** @param  list<ChangeStep>  $steps */
    private function createTables(App $app, array $steps): void
    {
        if ($steps === []) {
            return;
        }

        $tables = array_map(static fn (ChangeStep $s): array => [
            ...Arr::except($s->target, ['key', 'is_shared']),
            'fields' => array_map(static fn (array $f): array => Arr::except($f, ['key', 'is_shared']), (array) $s->target['fields']),
        ], $steps);
        $this->importer->import(['format' => SchemaExporter::FORMAT, 'tables' => $tables], app: $app);

        foreach ($steps as $step) {
            $model = CustomTable::query()->where('name', (string) $step->target['name'])->firstOrFail();
            $model->forceFill(['uuid' => $step->key, 'is_shared' => (bool) ($step->target['is_shared'] ?? false)])->saveQuietly();

            foreach ((array) $step->target['fields'] as $field) {
                CustomField::query()->where('custom_table_id', $model->id)->where('name', $field['name'])
                    ->update(['uuid' => $field['key'], 'is_shared' => (bool) ($field['is_shared'] ?? false)]);
            }

            $this->done[] = $step->label;
        }
    }

    /** @param  list<ChangeStep>  $steps */
    private function createFields(array $steps): void
    {
        $order = static fn (ChangeStep $s): int => match (true) {
            ($s->target['type'] ?? null) === FieldType::Relation->value => 1,
            ($s->target['type'] ?? null) === FieldType::Computed->value => 3,
            ! empty($s->target['options'][CopySource::OPTION]) || ! empty($s->target['options'][SequenceSuggester::SCOPE]) => 2,
            default => 0,
        };
        usort($steps, static fn (ChangeStep $a, ChangeStep $b): int => $order($a) <=> $order($b));
        $pending = $steps;

        // Berechnete Felder können auf andere neue berechnete Felder aufbauen: wiederholen, solange es vorangeht.
        while ($pending !== []) {
            $failed = [];
            $error = null;

            foreach ($pending as $step) {
                try {
                    $field = $this->service->addField($this->table((string) $step->table), Arr::except($step->target, ['key', 'is_shared']));
                    $field->forceFill(['uuid' => $step->key])->saveQuietly();
                    $this->done[] = $step->label;
                } catch (BusinessRuleException $e) {
                    if ($order($step) !== 3) {
                        throw $e;
                    }

                    $failed[] = $step;
                    $error = $e;
                }
            }

            if ($failed !== [] && count($failed) === count($pending)) {
                throw $error;
            }

            $pending = $failed;
        }
    }

    private function removeField(ChangeStep $step): void
    {
        $field = CustomField::query()->where('uuid', $step->key)->first();

        if ($field === null) {
            return;
        }

        if ($field->is_active) {
            $field = $this->service->updateField($field, ['is_active' => false]);
        }

        if ($step->action === ChangeStep::DELETE) {
            $this->service->dropField($field->refresh(), $field->name);
        }
    }

    private function removeTable(ChangeStep $step): void
    {
        $table = CustomTable::query()->where('uuid', $step->key)->first();

        if ($table === null) {
            return;
        }

        if ($table->is_active) {
            $table = $this->service->updateTable($table, ['is_active' => false]);
        }

        if ($step->action === ChangeStep::DELETE) {
            $this->service->dropTable($table->refresh(), $table->name);
        }
    }

    /**
     * Änderungen desselben Objekts (Paket und gewählter Konflikt) zu einem Schritt zusammenführen.
     *
     * @param  list<ChangeStep>  $steps
     * @return list<ChangeStep>
     */
    private function merged(array $steps): array
    {
        $byKey = [];

        foreach ($steps as $step) {
            $byKey[$step->key] = isset($byKey[$step->key])
                ? $byKey[$step->key]->with(['changes' => [...$byKey[$step->key]->changes, ...$step->changes]])
                : $step;
        }

        return array_values($byKey);
    }

    /**
     * @param  list<array{attr: string, from: mixed, to: mixed}>  $changes
     * @return array<string, mixed>
     */
    private static function values(array $changes): array
    {
        return array_column($changes, 'to', 'attr');
    }

    /**
     * Optionen vollständig aus dem Paket (ohne Syntaxbaum – er entsteht aus der Formel).
     *
     * @param  list<array{attr: string, from: mixed, to: mixed}>  $changes
     * @return array<string, mixed>
     */
    private static function options(array $changes): array
    {
        foreach ($changes as $change) {
            if ($change['attr'] === 'options') {
                return ['options' => (array) $change['to']];
            }
        }

        return [];
    }

    private function table(string $key): CustomTable
    {
        return CustomTable::query()->where('uuid', $key)->with('fields')->firstOrFail();
    }

    private function field(string $key): CustomField
    {
        return CustomField::query()->where('uuid', $key)->firstOrFail();
    }
}
