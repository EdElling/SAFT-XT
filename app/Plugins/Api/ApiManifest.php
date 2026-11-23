<?php

declare(strict_types=1);

namespace App\Plugins\Api;

use App\DynamicData\Write\StaleRecordException;
use App\Filament\Concerns\LimitsFormWidth;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Plugins\MenuSuggestion;
use App\Plugins\PluginServiceProvider;
use App\Plugins\SaftPlugin;
use App\Plugins\Testing\PluginTestCase;
use App\Tasks\TaskResult;
use App\Tasks\TaskSkipped;
use App\Tasks\TaskType;

/**
 * Was zur Programmierschnittstelle für Plugins gehört (intern, für Tests und Dokumentation). Die öffentlichen Methoden,
 * Eigenschaften und Konstanten der CLASSES sind zugesichert und werden gegen tests/Feature/Plugins/plugin-api.json
 * geprüft; von den TYPES ist nur die Klasse als Typ zugesichert (Eloquent-Verhalten), nicht ihre übrigen Methoden.
 *
 * @internal
 */
final class ApiManifest
{
    /** @var list<class-string> */
    public const array CLASSES = [
        SaftPlugin::class,
        PluginServiceProvider::class,
        MenuSuggestion::class,
        Saft::class,
        DataTable::class,
        DataAction::class,
        DashboardWidget::class,
        FormulaFunction::class,
        FieldRule::class,
        // Handbücher aus Plugins (SaftPlugin::manuals()), seit API 1.6.
        Manual::class,
        RecordListener::class,
        RecordRejected::class,
        StaleRecordException::class,
        Events\RecordEvent::class,
        Events\RecordSaving::class,
        Events\RecordSaved::class,
        Events\RecordDeleting::class,
        Events\RecordDeleted::class,
        LimitsFormWidth::class,
        PluginTestCase::class,
        // Arten geplanter Aufgaben (SaftPlugin::taskTypes()), seit API 1.5.
        TaskType::class,
        TaskResult::class,
        TaskSkipped::class,
        // Dateien aus Dateifeldern (DataTable::files(), attachFile(), detachFile()), seit API 1.8.
        StoredFile::class,
    ];

    /** @var list<class-string> */
    public const array TYPES = [
        DynamicRecord::class,
        User::class,
    ];

    /** Höchste bereitgestellte API-Version, z. B. „1.0“. */
    public static function current(): string
    {
        $versions = (array) config('saft.api_versions', []);
        usort($versions, 'version_compare');

        return (string) end($versions);
    }

    /** @return list<string> alle bereitgestellten Versionen (eine je Major-Version) */
    public static function versions(): array
    {
        return array_values(array_map('strval', (array) config('saft.api_versions', [])));
    }

    public static function covers(string $class): bool
    {
        return in_array($class, self::CLASSES, true) || in_array($class, self::TYPES, true);
    }
}
