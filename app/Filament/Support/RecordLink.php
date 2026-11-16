<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\DynamicData\DataAccess;
use App\DynamicData\DataMenu;
use App\DynamicData\Ui\KeylessRow;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\CustomTableResource;
use App\Filament\Resources\TableConnector\TableConnectorResource;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Link auf den Datensatz eines Protokolleintrags – nur wenn eine Resource existiert
 * und der aktuelle Benutzer den Datensatz sehen bzw. bearbeiten darf.
 */
final class RecordLink
{
    public static function forActivity(Activity $activity): ?string
    {
        $subject = $activity->subject ?? self::keyedSubject($activity);

        return $subject instanceof Model ? self::forRecord($subject) : null;
    }

    public static function forRecord(Model $record): ?string
    {
        if ($record instanceof DynamicRecord) {
            return self::forDynamicRecord($record);
        }

        // Tabellen und Felder: Table-Designer bzw. Table-Connector, je nach Herkunft der Tabelle.
        if ($record instanceof CustomField) {
            $record = $record->custom_table;

            if (! $record instanceof CustomTable) {
                return null;
            }
        }

        if ($record instanceof CustomTable) {
            $resource = $record->isExternal() ? TableConnectorResource::class : CustomTableResource::class;

            return $resource::canEdit($record) ? $resource::getUrl('edit', ['record' => $record]) : null;
        }

        $resource = Filament::getModelResource($record);

        if ($resource === null) {
            return null;
        }

        foreach (['view' => 'canView', 'edit' => 'canEdit'] as $page => $ability) {
            if ($resource::hasPage($page) && $resource::$ability($record)) {
                return $resource::getUrl($page, ['record' => $record]);
            }
        }

        return null;
    }

    private static function forDynamicRecord(DynamicRecord $record): ?string
    {
        $table = CustomTable::query()->where('name', $record->definitionName())->first();

        if ($table === null || ! $table->is_active || ! DataAccess::current($table, 'view')) {
            return null;
        }

        // Liste mit vorausgewähltem Datensatz (Kaskade zeigt ihn samt Details); gelöschte Datensätze nur
        // mit Löschrecht (wie in der Liste). Ohne einzelnen Schlüssel ist keine Auswahl möglich: nur die Liste.
        $selectable = $table->hasSingleKey() && (! $record->trashed() || DataAccess::current($table, 'delete'));

        return DynamicDataPage::urlFor($table->name, $selectable ? $record->getKey() : null, DataMenu::firstMenuIdFor($table->name));
    }

    /**
     * Angebundene Tabellen mit Text-, UUID- oder zusammengesetztem Schlüssel: Der Schlüssel steht in den
     * Eigenschaften („key“), nicht in subject_id.
     */
    private static function keyedSubject(Activity $activity): ?DynamicRecord
    {
        $key = $activity->properties['key'] ?? null;

        if (! is_string($key) || ! DynamicRecord::isMorphType($activity->subject_type)) {
            return null;
        }

        try {
            $model = DynamicRecord::forMorphType((string) $activity->subject_type);
            $table = $model === null ? null : CustomTable::query()->with('fields')->where('name', $model->definitionName())->first();

            if ($model === null || $table === null) {
                return null;
            }

            $query = $model->newQuery();

            return ($table->hasSingleKey() ? $query->whereKey($key) : KeylessRow::constrain($table, $query, $key))?->first();
        } catch (Throwable) {
            // Externe Datenbank nicht erreichbar: kein Link.
            return null;
        }
    }
}
