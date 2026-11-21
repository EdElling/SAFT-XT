<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung;

use App\Plugins\Api\DataAction;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\Manual;
use App\Plugins\Api\RecordListener;
use App\Plugins\MenuSuggestion;
use App\Plugins\SaftPlugin;
use Saft\MusikschulePlanung\Pages\Stundenplan;

/**
 * Beispiel-Plugin für die Demo „Musikschule“: Stundenplan je Lehrperson, Raum oder Schüler (Seite unter src/Pages wird
 * automatisch gefunden), Konfliktprüfung beim Speichern eines Kurses und Semester-Fortschreibung.
 */
final class MusikschulePlanungPlugin extends SaftPlugin
{
    public const string ID = 'musikschule-planung';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('musikschule-planung::planung.plugin');
    }

    public function description(): string
    {
        return __('musikschule-planung::planung.description');
    }

    public function permissions(): array
    {
        return [
            'timetable' => __('musikschule-planung::planung.permissions.timetable'),
            'rollover' => __('musikschule-planung::planung.permissions.rollover'),
        ];
    }

    public function requiredTables(): array
    {
        return School::REQUIRED;
    }

    /** Konfliktprüfung (verbietet) und Hinweis zu den Plätzen des Raums (meldet nur). */
    public function recordListeners(): array
    {
        return [
            new RecordListener(School::COURSES, RecordSaving::class, CourseConflictGuard::class),
            new RecordListener(School::COURSES, RecordSaved::class, RoomCapacityHint::class),
        ];
    }

    /** Semester fortschreiben: je Zeile der Semester (Quelle vorbelegt) und über der Tabelle. */
    public function dataActions(): array
    {
        return [
            new DataAction(School::SEMESTERS, DataAction::RECORD, static fn () => RolloverAction::make(true)),
            new DataAction(School::SEMESTERS, DataAction::TABLE, static fn () => RolloverAction::make(false)),
        ];
    }

    public function manuals(): array
    {
        return [new Manual(
            id: 'manual',
            title: ['de' => 'Musikschule: Planung', 'en' => 'Music school: planning', 'fr' => 'École de musique : planification', 'it' => 'Scuola di musica: pianificazione'],
            path: 'docs/manual',
            permissions: [$this->permissionName('timetable'), $this->permissionName('rollover'), 'data.'.School::COURSES.'.view'],
        )];
    }

    public function menuSuggestions(): array
    {
        return [
            new MenuSuggestion(
                route: Stundenplan::ROUTE,
                label: ['de' => 'Stundenplan', 'en' => 'Timetable', 'fr' => 'Horaire', 'it' => 'Orario'],
                icon: 'heroicon-o-calendar-days',
                permission: $this->permissionName('timetable'),
                group: ['de' => 'Musikschule', 'en' => 'Music school', 'fr' => 'École de musique', 'it' => 'Scuola di musica'],
                sortOrder: 5,
                subgroup: ['de' => 'Unterricht', 'en' => 'Teaching', 'fr' => 'Enseignement', 'it' => 'Insegnamento'],
            ),
        ];
    }
}
