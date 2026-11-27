<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\DynamicData\Files\RecordFiles;
use App\DynamicData\History\LoggedChanges;
use App\DynamicData\Ui\KeylessRow;
use App\DynamicData\Write\WriteOptions;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Bereitet attribute_changes eines Activity-Eintrags als Vorher/Nachher-Zeilen auf.
 */
final class ActivityChanges
{
    /**
     * Zeilen für die Anzeige: bei Datensätzen lesbar (Bezeichnungen, Werte im Format der Anwendung, B-174), sonst
     * technisch.
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    public static function rows(Activity $activity): array
    {
        return app(LoggedChanges::class)->rows($activity) ?? self::technicalRows($activity);
    }

    /**
     * Zeilen mit Spaltennamen und Werten wie im Protokoll (Änderungsprotokoll der Administration, Fehlersuche).
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    public static function technicalRows(Activity $activity): array
    {
        /** @var Collection<string, mixed>|null $changes */
        $changes = $activity->attribute_changes;

        $new = (array) ($changes?->get('attributes') ?? []);
        $old = (array) ($changes?->get('old') ?? []);

        $rows = [];

        foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $field) {
            $rows[] = [
                'field' => (string) $field,
                'old' => array_key_exists($field, $old) ? self::format($old[$field]) : '—',
                'new' => array_key_exists($field, $new) ? self::format($new[$field]) : '—',
            ];
        }

        return $rows;
    }

    /** Je Feld eine Zeile „Feld: alt → neu“ (Verlauf eines Datensatzes, B-174). */
    public static function lines(Activity $activity): string
    {
        $lines = array_map(self::line(...), self::rows($activity));

        return $lines === [] ? '—' : implode("\n", $lines);
    }

    /** @param  array{field: string, old: string, new: string}  $row */
    private static function line(array $row): string
    {
        return $row['old'] === '—' ? "{$row['field']}: {$row['new']}" : "{$row['field']}: {$row['old']} → {$row['new']}";
    }

    /** Kurzfassung der Änderungen, z. B. "roles: benutzer → leitung; name: A → B". */
    public static function summary(Activity $activity, int $limit = 160): string
    {
        $parts = array_map(self::line(...), self::rows($activity));

        return $parts === [] ? '—' : Str::limit(implode('; ', $parts), $limit);
    }

    /**
     * Wer die Änderung ausgelöst hat. „System“ nur, wenn wirklich kein Benutzer beteiligt war:
     * Die Relation liefert auch für gelöschte Benutzer nichts, die ID bleibt aber gespeichert.
     */
    public static function causerLabel(Activity $activity, ?string $system = null): string
    {
        if ($activity->causer_id === null) {
            return $system ?? __('activity.system');
        }

        $name = $activity->causer?->getAttribute('name');

        if ($name !== null) {
            return (string) $name;
        }

        $softDeleted = User::onlyTrashed()->whereKey($activity->causer_id)->value('name');

        return $softDeleted !== null ? __('activity.deleted_user', ['name' => $softDeleted]) : __('activity.removed_user', ['id' => $activity->causer_id]);
    }

    /**
     * Ereignis eines Eintrags mit Herkunft, z. B. „geändert (Sammeländerung)“ – gleich in Verlauf, Widgets und
     * Änderungsprotokoll (B-174).
     */
    public static function eventText(Activity $activity): string
    {
        $origin = ($activity->properties['via'] ?? null) === WriteOptions::BULK ? ' ('.__('data.bulk.history').')' : '';

        return self::eventLabel($activity->event).$origin;
    }

    /** Lesbarer Name eines Ereignisses; unbekannte erscheinen unverändert. */
    public static function eventLabel(?string $event): string
    {
        return $event !== null && Lang::has($key = "activity.events.{$event}") ? __($key) : (string) $event;
    }

    /**
     * @return array<string, string>
     */
    public static function eventOptions(): array
    {
        $events = ['created', 'updated', 'deleted', 'restored', 'login', 'login_failed', 'logout', 'exported', 'imported', 'role_simulation_started', 'role_simulation_stopped', 'snapshot_created', 'snapshot_deleted', 'password_changed', 'assistant', 'document', 'document_sent'];

        return array_combine($events, array_map(self::eventLabel(...), $events));
    }

    public static function subjectLabel(Activity $activity): string
    {
        if (DynamicRecord::isMorphType($activity->subject_type)) {
            $key = $activity->subject_id ?? $activity->properties['key'] ?? '?';

            return self::typeLabel((string) $activity->subject_type).' #'.(is_string($key) ? KeylessRow::describe($key) : $key);
        }

        if ($activity->subject_type === null) {
            // z. B. Einstellungen (spatie/laravel-settings) ohne Model-Bezug.
            return $activity->log_name !== null && str_starts_with($activity->log_name, 'settings.')
                ? __('activity.settings', ['group' => substr($activity->log_name, 9)])
                : '—';
        }

        return class_basename($activity->subject_type).' #'.$activity->subject_id;
    }

    private static function format(mixed $value): string
    {
        // Dateifelder stehen seit 0.8.1 roh im Protokoll, als JSON-Text wie in der Datenbank.
        if (is_string($value) && str_starts_with(ltrim($value), '[') && is_array($decoded = json_decode($value, true))
            && ($names = RecordFiles::names($decoded)) !== [] && count($names) === count($decoded)) {
            return implode(', ', $names);
        }

        return match (true) {
            $value === null => '∅',
            is_bool($value) => $value ? __('common.yes') : __('common.no'),
            is_scalar($value) => (string) $value,
            // Dateifelder (B-021): Liste aus Kennung und Name → Namen.
            ($names = RecordFiles::names($value)) !== [] && count($names) === count((array) $value) => implode(', ', $names),
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /** Lesbarer Name eines subject_type (Klasse oder "dynamic:<tabelle>"). */
    public static function typeLabel(string $type): string
    {
        if (! DynamicRecord::isMorphType($type)) {
            return class_basename($type);
        }

        $name = substr($type, strlen(DynamicRecord::MORPH_PREFIX));
        $labels = once(static fn (): array => CustomTable::labelOptions());

        return __('roles.data_resource', ['table' => $labels[$name] ?? $name]);
    }
}
