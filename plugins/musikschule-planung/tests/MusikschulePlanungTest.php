<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung\Tests;

use App\Apps\Definition\AppInstaller;
use App\Filament\Pages\DynamicDataPage;
use App\Models\DynamicRecord;
use App\Plugins\Api\RecordRejected;
use App\Plugins\Api\Saft;
use App\Plugins\Testing\PluginTestCase;
use Database\Seeders\MusikschuleDemoSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Saft\MusikschulePlanung\MusikschulePlanungPlugin;
use Saft\MusikschulePlanung\Pages\Stundenplan;
use Saft\MusikschulePlanung\School;
use Saft\MusikschulePlanung\SemesterRollover;

/** Beispiel-Plugin „Musikschule: Planung“: Konfliktprüfung, Hinweis zu den Plätzen, Fortschreibung, Stundenplan. */
class MusikschulePlanungTest extends PluginTestCase
{
    /** @var array<string, int> */
    private array $ids = [];

    /** Musikschul-App aus der App-Definition der Demo, mit wenigen Stammdaten. */
    private function school(): void
    {
        $admin = $this->actingAsSuperAdmin();
        app(AppInstaller::class)->install(app(MusikschuleDemoSeeder::class)->appDefinition(), null, $admin);

        $insert = fn (string $table, array $values): int => (int) DynamicRecord::forTableName('musikschule_'.$table)->create($values)->getKey();
        $this->ids = [
            'klavier' => $insert('faecher', ['bezeichnung' => 'Klavier']),
            'gesang' => $insert('faecher', ['bezeichnung' => 'Gesang']),
            'einzel45' => $insert('kursarten', ['bezeichnung' => 'Einzel 45', 'form' => 'einzel', 'dauer_minuten' => 45, 'tarif_semester' => '760.00']),
            'gruppe' => $insert('kursarten', ['bezeichnung' => 'Gruppe', 'form' => 'gruppe', 'dauer_minuten' => 60, 'tarif_semester' => '380.00']),
            'hs' => $insert('semester', ['bezeichnung' => 'HS 2026', 'beginn' => '2026-08-17', 'ende' => '2027-01-29']),
            'fs' => $insert('semester', ['bezeichnung' => 'FS 2027', 'beginn' => '2027-02-15', 'ende' => '2027-07-02']),
            'raum1' => $insert('raeume', ['bezeichnung' => 'Raum 1', 'plaetze' => 3]),
            'saal' => $insert('raeume', ['bezeichnung' => 'Saal', 'plaetze' => 40]),
            'moretti' => $insert('lehrpersonen', ['vorname' => 'Luca', 'nachname' => 'Moretti']),
            'keller' => $insert('lehrpersonen', ['vorname' => 'Sarah', 'nachname' => 'Keller']),
            'mia' => $insert('schueler', ['vorname' => 'Mia', 'nachname' => 'Huber']),
            'leo' => $insert('schueler', ['vorname' => 'Leo', 'nachname' => 'Huber']),
        ];
    }

    /** @param  array<string, mixed>  $values */
    private function course(string $name, string $day, string $time, array $values = []): DynamicRecord
    {
        return DynamicRecord::forTableName(School::COURSES)->create([
            'bezeichnung' => $name, 'wochentag' => $day, 'uhrzeit' => $time, 'max_teilnehmer' => 1, 'fach' => $this->ids['klavier'],
            'kursart' => $this->ids['einzel45'], 'lehrperson' => $this->ids['moretti'], 'raum' => $this->ids['raum1'], 'semester' => $this->ids['hs'],
            ...$values,
        ]);
    }

    private function enrol(DynamicRecord $course, string $student, string $status = 'aktiv', ?string $discount = null): void
    {
        DynamicRecord::forTableName(School::ENROLMENTS)->create(['kurs' => $course->getKey(), 'schueler' => $this->ids[$student],
            'status' => $status, 'datum' => '2026-06-15', 'rabatt_prozent' => $discount]);
    }

    public function test_conflicts_of_teacher_and_room_are_rejected(): void
    {
        $this->school();
        $this->course('Klavier Mia', 'mo', '16:00');

        // Angrenzend (16:45), anderer Tag, anderes Semester: erlaubt.
        $this->course('Klavier Leo', 'mo', '16:45');
        $this->course('Klavier Di', 'di', '16:00');
        $this->course('Klavier FS', 'mo', '16:00', ['semester' => $this->ids['fs']]);

        try {
            $this->course('Gesang', 'mo', '16:30', ['lehrperson' => $this->ids['keller'], 'fach' => $this->ids['gesang']]);
            $this->fail('Raum doppelt belegt.');
        } catch (RecordRejected $e) {
            $this->assertSame('Raum 1 ist am Montag 16:00–16:45 bereits mit „Klavier Mia“ belegt.', $e->getMessage());
        }

        try {
            $this->course('Klavier Saal', 'mo', '15:30', ['raum' => $this->ids['saal'], 'kursart' => $this->ids['gruppe']]);
            $this->fail('Lehrperson doppelt belegt.');
        } catch (RecordRejected $e) {
            $this->assertSame('Luca Moretti unterrichtet am Montag 16:00–16:45 bereits „Klavier Mia“.', $e->getMessage());
        }

        // Ändern: nur geprüft, wenn sich Zeit, Raum usw. ändern.
        $leo = DynamicRecord::forTableName(School::COURSES)->newQuery()->where('bezeichnung', 'Klavier Leo')->sole();
        $leo->update(['bezeichnung' => 'Klavier Leo H.']);
        $this->expectException(RecordRejected::class);
        $leo->update(['uhrzeit' => '16:15']);
    }

    public function test_room_too_small_only_notifies(): void
    {
        $this->school();
        $course = $this->course('Gruppe Raum 1', 'sa', '10:00', ['max_teilnehmer' => 8, 'kursart' => $this->ids['gruppe']]);

        $this->assertNotNull($course->getKey());
        $this->assertStringContainsString('Raum 1 hat nur 3 Plätze', (string) auth()->user()->notifications()->latest()->first()?->data['body']);
    }

    public function test_rollover_copies_courses_and_moves_enrolments_to_the_waiting_list(): void
    {
        $this->travelTo('2026-12-01 10:00:00');
        $this->school();
        $mia = $this->course('Klavier Mia', 'mo', '16:00', ['tarif' => '700.00']);
        $leo = $this->course('Klavier Leo', 'mo', '16:45');
        $this->enrol($mia, 'mia');
        $this->enrol($leo, 'leo', 'aktiv', '10.00');
        $this->enrol($leo, 'mia', 'abgemeldet');
        $this->course('Klavier Leo', 'di', '17:00', ['semester' => $this->ids['fs']]);

        $rollover = app(SemesterRollover::class);
        $this->assertSame(['courses' => ['Klavier Mia'], 'skipped' => ['Klavier Leo'], 'enrolments' => 1], $rollover->preview($this->ids['hs'], $this->ids['fs']));

        $this->assertSame(['courses' => 1, 'skipped' => ['Klavier Leo'], 'enrolments' => 1], $rollover->run($this->ids['hs'], $this->ids['fs']));

        $copy = DynamicRecord::forTableName(School::COURSES)->newQuery()->where('semester', $this->ids['fs'])->where('bezeichnung', 'Klavier Mia')->sole();
        $this->assertSame(['mo', '16:00', $this->ids['raum1'], '760.00'], [$copy->wochentag, $copy->uhrzeit, (int) $copy->raum, (string) $copy->tarif], 'Tarif neu aus der Kursart.');
        $enrolment = DynamicRecord::forTableName(School::ENROLMENTS)->newQuery()->where('kurs', $copy->getKey())->sole();
        $this->assertSame([$this->ids['mia'], 'warteliste', '2026-12-01'], [(int) $enrolment->schueler, $enrolment->status, substr((string) $enrolment->datum, 0, 10)]);

        // Ohne Raum und Zeit, alter Tarif, ohne Anmeldungen – in ein drittes Semester.
        $next = (int) DynamicRecord::forTableName(School::SEMESTERS)->create(['bezeichnung' => 'HS 2027', 'beginn' => '2027-08-16', 'ende' => '2028-01-28'])->getKey();
        $this->assertSame(['courses' => 2, 'skipped' => [], 'enrolments' => 0], $rollover->run($this->ids['hs'], $next, keepSchedule: false, withEnrolments: false, newTariff: false));
        $old = DynamicRecord::forTableName(School::COURSES)->newQuery()->where('semester', $next)->where('bezeichnung', 'Klavier Mia')->sole();
        $this->assertSame([null, null, null, '700.00'], [$old->wochentag, $old->uhrzeit, $old->raum, (string) $old->tarif]);
    }

    public function test_rollover_is_all_or_nothing(): void
    {
        $this->school();
        $this->course('Klavier Mia', 'mo', '16:00');
        $this->course('Klavier Leo', 'mo', '16:45');
        // Im Ziel belegt Keller schon Raum 1 um 16:45 – der zweite Kurs scheitert, der erste wird zurückgenommen.
        $this->course('Gesang FS', 'mo', '17:00', ['semester' => $this->ids['fs'], 'lehrperson' => $this->ids['keller']]);

        try {
            app(SemesterRollover::class)->run($this->ids['hs'], $this->ids['fs']);
            $this->fail('Konflikt erwartet.');
        } catch (RecordRejected) {
        }

        $this->assertSame(['Gesang FS'], DynamicRecord::forTableName(School::COURSES)->newQuery()->where('semester', $this->ids['fs'])->pluck('bezeichnung')->all());
    }

    public function test_rollover_action_on_a_semester_row(): void
    {
        $this->school();
        $this->enrol($this->course('Klavier Mia', 'mo', '16:00'), 'mia');

        Livewire::test(DynamicDataPage::class, ['table' => School::SEMESTERS])
            ->callAction(TestAction::make('musikschuleFortschreiben')->table(Saft::table(School::SEMESTERS)->withoutAuthorization()->find($this->ids['hs'])), [
                'ziel' => $this->ids['fs'],
            ])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame(1, DynamicRecord::forTableName(School::COURSES)->newQuery()->where('semester', $this->ids['fs'])->count());
    }

    public function test_timetable_per_teacher_room_and_student(): void
    {
        $this->travelTo('2026-11-21 10:00:00');
        $this->school();
        $mia = $this->course('Klavier Mia', 'mo', '16:00');
        $this->course('Klavier Leo', 'mo', '16:45');
        $this->course('Chor', 'mi', '14:00', ['lehrperson' => $this->ids['keller'], 'raum' => $this->ids['saal'], 'kursart' => $this->ids['gruppe'], 'fach' => $this->ids['gesang'], 'max_teilnehmer' => 8]);
        $this->course('Ohne Zeit', 'mo', '', ['lehrperson' => $this->ids['keller'], 'raum' => null]);
        $this->enrol($mia, 'mia');

        $page = Livewire::test(Stundenplan::class)->assertOk()
            ->assertSet('data.semester', $this->ids['hs'])
            ->assertSet('data.ansicht', Stundenplan::TEACHER)
            ->assertSet('data.auswahl', $this->ids['keller']); // nach Nachname: Keller vor Moretti

        $plan = $page->instance()->timetable();
        $this->assertSame(['Chor'], array_column($plan['days']['mi'], 'name'));
        $this->assertSame(['Ohne Zeit'], array_column($plan['unscheduled'], 'name'));
        $this->assertSame(['Gesang', 'Klavier'], array_keys($plan['legend']), 'Auch Fächer der Kurse ohne Zeit.');
        $this->assertArrayNotHasKey('so', $plan['days']);

        $page->set('data.ansicht', Stundenplan::ROOM)->set('data.auswahl', $this->ids['raum1']);
        $plan = $page->instance()->timetable();
        $this->assertSame(['Klavier Mia', 'Klavier Leo'], array_column($plan['days']['mo'], 'name'));
        $this->assertSame(['16:00–16:45', '16:45–17:30'], array_column($plan['days']['mo'], 'time'));
        $this->assertStringEndsWith('/admin/data/musikschule_kurse?sel='.$mia->getKey(), $plan['days']['mo'][0]['url'], 'Klick öffnet den Kurs (API 1.9).');
        $this->assertSame([16 * 60, 18 * 60], [$plan['from'], $plan['to']]);
        $this->assertSame(0, $plan['conflicts']);
        $page->assertSee('Klavier Mia')->assertSee('Luca Moretti');

        $page->set('data.ansicht', Stundenplan::STUDENT)->set('data.auswahl', $this->ids['mia']);
        $this->assertSame(['Klavier Mia'], array_column($page->instance()->timetable()['days']['mo'], 'name'));
        $this->assertStringContainsString('1 von 1 Plätzen belegt', $page->instance()->timetable()['days']['mo'][0]['tooltip']);
    }

    public function test_timetable_shows_overlaps_side_by_side(): void
    {
        $this->school();
        $this->course('Klavier Mia', 'mo', '16:00');
        // Überschneidung aus der Zeit vor dem Plugin (an der Prüfung vorbei angelegt).
        DynamicRecord::withoutPluginEvents(fn () => $this->course('Klavier Alt', 'mo', '16:30'));
        $this->course('Klavier Leo', 'mo', '17:15');

        $page = Livewire::test(Stundenplan::class)->set('data.auswahl', $this->ids['moretti']);
        $monday = $page->instance()->timetable()['days']['mo'];

        $this->assertSame([[0, 2, true], [1, 2, true], [0, 1, false]], array_map(static fn (array $b): array => [$b['lane'], $b['lanes'], $b['conflict']], $monday));
        $page->assertSee('2 Kurse überschneiden sich');
    }

    public function test_access_needs_the_permissions(): void
    {
        $this->school();
        $this->actingWithPermissions(['data.'.School::COURSES.'.view', 'data.'.School::SEMESTERS.'.view']);

        $this->get(Stundenplan::getUrl())->assertForbidden();

        $this->actingWithPermissions([MusikschulePlanungPlugin::ID.'.timetable']);
        $this->get(Stundenplan::getUrl())->assertOk()->assertSee('Sie dürfen die Kurse nicht sehen.');
    }
}
