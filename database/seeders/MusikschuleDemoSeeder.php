<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Apps\Definition\AppInstaller;
use App\Apps\Update\ChangeExecutor;
use App\Apps\Update\ChangePlanner;
use App\Documents\Templates\TemplateFields;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\User;
use Database\Seeders\Concerns\SeedsDemoTables;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Musikschule (Demo): eine mittelgrosse App mit 21 Tabellen, entworfen mit dem KI-App-Designer (B-107, Etappe 7) und
 * von Hand verbessert. Die App selbst kommt aus der App-Definition database/seeders/apps/musikschule.saft-app.json und
 * wird über den normalen Installer angelegt – derselbe Weg wie „App installieren“. Dazu Beispieldaten: ein Semester
 * mit Unterricht, Präsenzen, Rechnungen und Zahlungen, ein zweites begonnen.
 *
 *   php artisan db:seed --class=MusikschuleDemoSeeder
 *
 * Wiederholbar: Ist die App schon da (auch unter anderem Namen), ergänzt der Seeder nur fehlende Daten; ist die Demo
 * halb gefüllt (Beispieldaten vorhanden, aber eine Tabelle leer – z. B. nach einem abgebrochenen Lauf), lädt er alle
 * Demo-Daten neu. Fehlt ihr die Tabelle Ferien (App-Stand 1.0), wird sie zuerst aktualisiert – derselbe Weg wie
 * „Aktualisieren …“ auf der Seite Apps, nur mit den vorgewählten Schritten.
 */
class MusikschuleDemoSeeder extends Seeder
{
    use SeedsDemoTables;

    /** Schlüssel der Demo-Vorlage „Semesterrechnung“ (B-124; aus der App-Definition, Teil „documents“). */
    public const string INVOICE_TEMPLATE = '8b6e2d94-1f3a-4c7e-a05d-6e9c3b7f2a81';

    public const string FILE = 'seeders/apps/musikschule.saft-app.json';

    /** Ferien und Feiertage der Beispieldaten (Kanton Zürich): Bezeichnung, von, bis */
    public const array HOLIDAYS = [
        ['Herbstferien', '2026-10-05', '2026-10-16'],
        ['Weihnachtsferien', '2026-12-21', '2027-01-01'],
        ['Sportferien', '2027-02-08', '2027-02-19'],
        ['Karfreitag und Ostern', '2027-03-26', '2027-03-29'],
        ['Frühlingsferien', '2027-04-19', '2027-04-30'],
        ['Auffahrt', '2027-05-06', '2027-05-07'],
        ['Pfingstmontag', '2027-05-17', '2027-05-17'],
        ['Sommerferien', '2027-07-12', '2027-08-13'],
    ];

    /** @var array<string, mixed>|null */
    private ?array $definitionCache = null;

    /** @return array<string, mixed> die App-Definition aus der Datei */
    public function appDefinition(): array
    {
        return $this->definitionCache ??= json_decode((string) file_get_contents(database_path(self::FILE)), true, 512, JSON_THROW_ON_ERROR);
    }

    public function demoAppDefinition(): array
    {
        $app = $this->appDefinition()['app'];

        return ['uuid' => $app['key'], 'name' => $app['name'], 'prefix' => $app['prefix'], 'icon' => $app['icon'], 'sort_order' => 560, 'manual' => 'musikschule'];
    }

    public function run(): void
    {
        $app = self::installedApp();

        if ($app === null) {
            $this->install();
        } elseif (! $this->demoInstalled() || version_compare((string) $app->version, (string) $this->appDefinition()['app']['version'], '<')) {
            // Ältere App (fehlende Tabellen oder ältere Version, z. B. 1.1 ohne Dokumentvorlage) aktualisieren.
            $this->update($app);
        }

        $empty = $this->emptyDemoTables();

        if (in_array('musikschule_faecher', $empty, true)) {
            $this->seedRecords();
        } elseif (array_diff($empty, ['musikschule_ferien']) !== [] && $this->hasDemoRecords()) {
            // Halb gefüllt, z. B. nach einem abgebrochenen Lauf: alle Demo-Daten neu laden (in einer Transaktion). Nur wenn
            // die ersten Beispieldaten von diesem Seeder stammen – eigene Daten in einer gleichen App bleiben unberührt.
            $this->command?->warn('Musikschule (Demo) unvollständig, leer: '.implode(', ', $empty).' – Demo-Daten werden neu geladen.');
            $this->resetDemoData();
        } elseif (in_array('musikschule_ferien', $empty, true)) {
            $this->seedHolidays();
        }

        $this->seedDemoManual();
        // Zahlungsempfänger der QR-Rechnung (1.4), nur wo im Briefpapier noch nichts steht; Beispiel-IBAN von SIX.
        $this->demoQrCreditor('musikschule_rechnungen', [
            'firma' => 'Musikschule Muster', 'strasse' => 'Notenweg 3', 'plz' => '3011', 'ort' => 'Bern', 'land' => 'CH',
            'iban' => 'CH93 0076 2011 6238 5295 7',
        ]);
    }

    /** @return list<string> Demo-Tabellen ohne Datensätze */
    private function emptyDemoTables(): array
    {
        return array_values(array_filter($this->demoTableNames(), static fn (string $table): bool => DynamicRecord::forTableName($table)->newQuery()->doesntExist()));
    }

    /** Stammen die ersten Beispieldaten von diesem Seeder (Fach „Musiktheorie“ und Semester „HS 2026“)? */
    private function hasDemoRecords(): bool
    {
        return DynamicRecord::forTableName('musikschule_faecher')->newQuery()->where('bezeichnung', 'Musiktheorie')->exists()
            && DynamicRecord::forTableName('musikschule_semester')->newQuery()->where('bezeichnung', 'HS 2026')->exists();
    }

    /** Aus der App-Definition installieren (wie „App installieren“) und aktivieren. */
    private function install(): void
    {
        $app = app(AppInstaller::class)->install($this->appDefinition(), null, $this->superAdmin());
        $app->forceFill(['is_active' => true, 'sort_order' => 560])->save();
    }

    /**
     * Ältere App (z. B. 1.0 ohne Ferien) auf den Stand der Datei bringen: Änderungsplan wie bei „Aktualisieren …“, nur
     * die vorgewählten Schritte (nichts Zerstörendes, keine Konflikte mit eigenen Änderungen), ohne die App umzubenennen.
     * Vorher wird gesichert.
     */
    private function update(App $app): void
    {
        $user = $this->superAdmin();
        $plan = app(ChangePlanner::class)->plan($app, $this->appDefinition(), $user);

        if (! $plan->canApply()) {
            throw new RuntimeException('Musikschule: Aktualisieren nicht möglich – '.implode(' ', $plan->blockers));
        }

        // Einen eigenen Namen der App behalten (z. B. „Musikschule“ statt „Musikschule (Demo)“).
        $selected = array_values(array_filter($plan->preselected(), static fn (string $id): bool => ! collect($plan->step($id)?->changes ?? [])
            ->contains(static fn (array $c): bool => $plan->step($id)?->kind === 'app' && $c['attr'] === 'name')));

        if ($selected !== []) {
            app(ChangeExecutor::class)->apply($app, $plan, $selected, false, $user);
        }

        // Von 1.1 an: Die Semesterrechnung legte dieser Seeder schon an (ohne Ablage, E-Mail und QR-Rechnung). Im
        // installierten Stand 1.1 fehlt sie, der Änderungsplan sieht Ablage (1.2), E-Mail (1.3) und QR-Rechnung (1.4)
        // deshalb als Konflikt – bei der Demo übernehmen, was noch leer ist.
        $package = collect($this->appDefinition()['documents'] ?? [])->firstWhere('key', self::INVOICE_TEMPLATE);
        $template = DocumentTemplate::query()->where('uuid', self::INVOICE_TEMPLATE)->first();

        if ($package !== null && $template !== null) {
            foreach (['file_field', 'email_to', 'email_subject', 'email_body', 'qr_bill'] as $attribute) {
                if (blank($template->{$attribute}) && filled($package[$attribute] ?? null)
                    && ($attribute !== 'file_field' || TemplateFields::field($template->customTable, (string) $package[$attribute]) !== null)) {
                    $template->{$attribute} = $package[$attribute];
                }
            }

            $template->save();
        }
    }

    private function superAdmin(): User
    {
        return User::query()->orderBy('id')->get()->first(static fn (User $u): bool => $u->isSuperAdmin())
            ?? throw new RuntimeException('Kein Super-Admin vorhanden – zuerst „php artisan db:seed“ ausführen.');
    }

    private function seedHolidays(): void
    {
        foreach (self::HOLIDAYS as [$name, $from, $to]) {
            DynamicRecord::forTableName('musikschule_ferien')->create(['bezeichnung' => $name, 'von' => $from, 'bis' => $to]);
        }
    }

    /**
     * Tabellen der Demo in Anlegereihenfolge: Verknüpfungsziele vor den Tabellen, die auf sie zeigen (für das
     * Zurücksetzen in umgekehrter Reihenfolge).
     *
     * @return list<array{name: string}>
     */
    private function definitions(): array
    {
        $targets = [];

        foreach ($this->appDefinition()['tables'] as $table) {
            $targets[$table['name']] = array_values(array_filter(array_map(
                static fn (array $f): ?string => ($f['type'] ?? null) === 'relation' && ($f['options']['table'] ?? null) !== $table['name'] ? (string) $f['options']['table'] : null,
                $table['fields'],
            )));
        }

        $ordered = [];
        $visit = function (string $name) use (&$visit, &$ordered, $targets): void {
            if (in_array($name, $ordered, true) || ! isset($targets[$name])) {
                return;
            }

            foreach ($targets[$name] as $target) {
                $visit($target);
            }

            $ordered[] = $name;
        };

        foreach (array_keys($targets) as $name) {
            $visit($name);
        }

        return array_map(static fn (string $name): array => ['name' => $name], $ordered);
    }

    protected function reseedDemoData(): void
    {
        $this->seedRecords();
    }

    private function seedRecords(): void
    {
        $insert = fn (string $table, array $data): int => (int) DynamicRecord::forTableName('musikschule_'.$table)->create($data)->getKey();
        $this->seedHolidays();

        // Fächer, Kursarten, Semester, Räume
        $subjects = [];

        foreach (['Gitarre' => 'Akustische und klassische Gitarre', 'E-Gitarre' => 'Rock, Pop, Blues', 'Klavier' => 'Klassik und Pop', 'Gesang' => 'Stimmbildung und Repertoire',
            'Schlagzeug' => 'Drumset und Rhythmik', 'Violine' => null, 'Querflöte' => null, 'Musiktheorie' => 'Harmonielehre und Gehörbildung'] as $name => $text) {
            $subjects[$name] = $insert('faecher', ['bezeichnung' => $name, 'beschreibung' => $text]);
        }

        $kinds = [];

        foreach ([['Einzel 30', 'einzel', 30, '520.00'], ['Einzel 45', 'einzel', 45, '760.00'], ['Einzel 60', 'einzel', 60, '990.00'],
            ['Gruppenkurs', 'gruppe', 60, '380.00'], ['Workshop', 'workshop', 120, '120.00']] as [$name, $form, $minutes, $fee]) {
            $kinds[$name] = ['id' => $insert('kursarten', ['bezeichnung' => $name, 'form' => $form, 'dauer_minuten' => $minutes, 'tarif_semester' => $fee]), 'minutes' => $minutes];
        }

        $hs = $insert('semester', ['bezeichnung' => 'HS 2026', 'beginn' => '2026-08-17', 'ende' => '2027-01-29']);
        $fs = $insert('semester', ['bezeichnung' => 'FS 2027', 'beginn' => '2027-02-15', 'ende' => '2027-07-02']);

        $rooms = [];

        foreach ([['Saal', 40, 'Konzertflügel, Bühne, Beschallung'], ['Raum 1', 3, 'Flügel'], ['Raum 2', 4, 'Klavier, Notenständer'],
            ['Raum 3', 3, 'Drumset, schallgedämmt'], ['Bandraum', 8, 'Verstärker, Drumset, Gesangsanlage']] as [$name, $places, $equipment]) {
            $rooms[$name] = $insert('raeume', ['bezeichnung' => $name, 'plaetze' => $places, 'ausstattung' => $equipment]);
        }

        // Lehrpersonen mit Fächern
        $teachers = [];

        foreach ([
            ['Anna', 'Bucher', '105.00', 80, ['Gitarre', 'E-Gitarre']],
            ['Luca', 'Moretti', '110.00', 60, ['Klavier', 'Musiktheorie']],
            ['Sarah', 'Keller', '100.00', 50, ['Gesang']],
            ['Jonas', 'Frei', '95.00', 40, ['Schlagzeug']],
            ['Elena', 'Novak', '115.00', 50, ['Violine', 'Klavier']],
            ['Marc', 'Dubois', '100.00', 30, ['Querflöte', 'Musiktheorie']],
        ] as [$first, $last, $rate, $pensum, $teaches]) {
            $teachers[$last] = $insert('lehrpersonen', ['vorname' => $first, 'nachname' => $last, 'stundensatz' => $rate, 'pensum_prozent' => $pensum,
                'email' => strtolower($first.'.'.$last).'@musikschule.example', 'telefon' => '+41 44 555 '.(21 + count($teachers) * 7).' '.(30 + count($teachers) * 9)]);

            foreach ($teaches as $subject) {
                $insert('lehrfaecher', ['lehrperson' => $teachers[$last], 'fach' => $subjects[$subject]]);
            }
        }

        // Schülerinnen und Schüler; Minderjährige mit Kontaktpersonen (Geschwister teilen sie)
        $parents = [];

        foreach ([['Claudia', 'Meier'], ['Thomas', 'Meier'], ['Sandra', 'Huber'], ['Peter', 'Graf'], ['Nicole', 'Brunner'], ['Daniel', 'Weber'],
            ['Andrea', 'Schmid'], ['Marco', 'Rossi'], ['Fatma', 'Yilmaz'], ['Stefan', 'Baumann'], ['Ursula', 'Steiner'], ['Reto', 'Wyss']] as $i => [$first, $last]) {
            $parents[$i + 1] = $insert('kontaktpersonen', ['vorname' => $first, 'nachname' => $last, 'strasse' => ['Bahnhofstrasse', 'Seeweg', 'Dorfstrasse', 'Hauptgasse'][$i % 4].' '.(3 + $i * 4),
                'plz' => (string) (8000 + $i * 13), 'ort' => ['Zürich', 'Winterthur', 'Uster', 'Dübendorf'][$i % 4], 'telefon' => '+41 79 555 '.(10 + $i).' '.(20 + $i),
                'email' => strtolower($first).'.'.strtolower($last).'@example.ch']);
        }

        // Vorname, Nachname, Geburtsdatum, Eintritt, Kontaktpersonen (erste = Rechnungsempfänger), Austritt
        $students = [];

        foreach ([
            ['Lena', 'Meier', '2014-03-12', '2022-08-15', [1, 2]], ['Noah', 'Meier', '2016-07-02', '2024-08-19', [1, 2]],
            ['Mia', 'Huber', '2013-11-23', '2021-08-16', [3]], ['Elias', 'Graf', '2012-05-30', '2020-08-17', [4]],
            ['Lara', 'Brunner', '2015-01-17', '2023-08-14', [5]], ['Ben', 'Weber', '2011-09-08', '2019-08-19', [6]],
            ['Sofia', 'Schmid', '2014-06-21', '2025-02-17', [7]], ['Luca', 'Rossi', '2010-12-04', '2020-02-17', [8]],
            ['Aylin', 'Yilmaz', '2013-04-15', '2024-02-19', [9]], ['Jan', 'Baumann', '2016-10-30', '2025-08-18', [10]],
            ['Emma', 'Steiner', '2012-08-11', '2022-02-14', [11]], ['Tim', 'Wyss', '2015-02-25', '2024-08-19', [12]],
            ['Nina', 'Wyss', '2017-06-09', '2026-08-17', [12]], ['Leo', 'Huber', '2016-03-03', '2025-08-18', [3]],
            ['Julia', 'Brunner', '2011-12-19', '2021-08-16', [5], '2026-01-31'],
        ] as $s) {
            [$first, $last, $born, $since, $contacts] = $s;
            $id = $insert('schueler', ['vorname' => $first, 'nachname' => $last, 'geburtsdatum' => $born, 'eintritt' => $since, 'austritt' => $s[5] ?? null,
                'ort' => ['Zürich', 'Winterthur', 'Uster', 'Dübendorf'][count($students) % 4]]);
            $students[$first.' '.$last] = ['id' => $id, 'payer' => ['kontaktperson' => $parents[$contacts[0]]]];

            foreach ($contacts as $n => $contact) {
                $insert('schuelerkontakte', ['schueler' => $id, 'kontaktperson' => $parents[$contact], 'beziehung' => $n === 0 ? 'mutter' : 'vater', 'rechnungsempfaenger' => $n === 0]);
            }
        }

        foreach ([['Sarah', 'Fischer', '2001-05-14'], ['Michael', 'Zimmermann', '1987-09-02'], ['Laura', 'Bianchi', '1995-12-20'], ['David', 'Kunz', '1978-03-08'],
            ['Petra', 'Lang', '1964-07-27'], ['Kevin', 'Müller', '2004-01-15'], ['Chiara', 'Ferrari', '1999-10-03'], ['Martin', 'Roth', '1972-04-22'],
            ['Isabel', 'Gerber', '1990-08-30'], ['Simon', 'Hofer', '1983-11-11'], ['Lea', 'Suter', '2006-02-09'], ['Andreas', 'Vogel', '1968-06-16'],
            ['Nadia', 'Ammann', '1993-03-25'], ['Patrick', 'Egli', '1980-12-01'], ['Eva', 'Marti', '1958-09-19']] as $i => [$first, $last, $born]) {
            $id = $insert('schueler', ['vorname' => $first, 'nachname' => $last, 'geburtsdatum' => $born, 'eintritt' => ['2023-08-14', '2024-02-19', '2025-08-18', '2026-08-17'][$i % 4],
                'strasse' => 'Musterweg '.(10 + $i), 'plz' => (string) (8400 + $i * 7), 'ort' => ['Winterthur', 'Zürich', 'Baden', 'Uster'][$i % 4],
                'email' => strtolower($first.'.'.$last).'@example.ch', 'telefon' => '+41 78 555 '.(30 + $i).' '.(40 + $i)]);
            $students[$first.' '.$last] = ['id' => $id, 'payer' => ['schueler' => $id]];
        }

        // Kurse im HS 2026: Bezeichnung, Fach, Kursart, Lehrperson, Raum, Wochentag, Uhrzeit, Teilnehmende (Rabatt in % für Geschwister)
        $courses = [];

        foreach ([
            ['Gitarre Lena', 'Gitarre', 'Einzel 45', 'Bucher', 'Raum 2', 'mo', '15:00', ['Lena Meier']],
            ['Gitarre Noah', 'Gitarre', 'Einzel 30', 'Bucher', 'Raum 2', 'mo', '15:45', [['Noah Meier', 10]]],
            ['Gitarre Erwachsene', 'Gitarre', 'Gruppenkurs', 'Bucher', 'Bandraum', 'mi', '19:00', ['Michael Zimmermann', 'David Kunz', 'Simon Hofer', 'Andreas Vogel', 'Patrick Egli']],
            ['E-Gitarre Elias', 'E-Gitarre', 'Einzel 45', 'Bucher', 'Bandraum', 'di', '16:30', ['Elias Graf']],
            ['E-Gitarre Kevin', 'E-Gitarre', 'Einzel 60', 'Bucher', 'Bandraum', 'do', '18:00', ['Kevin Müller']],
            ['Klavier Mia', 'Klavier', 'Einzel 45', 'Moretti', 'Raum 1', 'mo', '16:00', ['Mia Huber']],
            ['Klavier Leo', 'Klavier', 'Einzel 30', 'Moretti', 'Raum 1', 'mo', '16:45', [['Leo Huber', 10]]],
            ['Klavier Laura', 'Klavier', 'Einzel 60', 'Moretti', 'Raum 1', 'di', '19:00', ['Laura Bianchi']],
            ['Klavier Emma', 'Klavier', 'Einzel 45', 'Novak', 'Saal', 'do', '16:00', ['Emma Steiner']],
            ['Gesang Lara', 'Gesang', 'Einzel 45', 'Keller', 'Raum 2', 'di', '15:30', ['Lara Brunner']],
            ['Gesang Erwachsene', 'Gesang', 'Gruppenkurs', 'Keller', 'Saal', 'mo', '19:30', ['Sarah Fischer', 'Isabel Gerber', 'Nadia Ammann', 'Eva Marti', 'Chiara Ferrari', 'Petra Lang']],
            ['Gesang Chiara', 'Gesang', 'Einzel 60', 'Keller', 'Raum 2', 'mi', '17:00', ['Chiara Ferrari']],
            ['Schlagzeug Ben', 'Schlagzeug', 'Einzel 45', 'Frei', 'Raum 3', 'mi', '16:00', ['Ben Weber']],
            ['Schlagzeug Tim', 'Schlagzeug', 'Einzel 30', 'Frei', 'Raum 3', 'mi', '16:45', ['Tim Wyss']],
            ['Schlagzeug Martin', 'Schlagzeug', 'Einzel 45', 'Frei', 'Raum 3', 'do', '19:00', ['Martin Roth']],
            ['Violine Sofia', 'Violine', 'Einzel 45', 'Novak', 'Raum 1', 'fr', '15:00', ['Sofia Schmid']],
            ['Violine Aylin', 'Violine', 'Einzel 30', 'Novak', 'Raum 1', 'fr', '15:45', ['Aylin Yilmaz']],
            ['Querflöte Lea', 'Querflöte', 'Einzel 45', 'Dubois', 'Raum 2', 'do', '17:00', ['Lea Suter']],
            ['Musiktheorie', 'Musiktheorie', 'Gruppenkurs', 'Moretti', 'Saal', 'sa', '10:00', ['Luca Rossi', 'Emma Steiner', 'Elias Graf', 'Kevin Müller', 'Lea Suter', 'Mia Huber', 'Ben Weber', 'Laura Bianchi']],
            ['Kinderchor', 'Gesang', 'Gruppenkurs', 'Keller', 'Saal', 'mi', '14:00', ['Nina Wyss', 'Jan Baumann', 'Sofia Schmid', 'Lara Brunner', 'Tim Wyss', 'Noah Meier']],
        ] as [$name, $subject, $kind, $teacher, $room, $day, $time, $members]) {
            $max = $kind === 'Gruppenkurs' ? 8 : 1;
            $id = $insert('kurse', ['bezeichnung' => $name, 'fach' => $subjects[$subject], 'kursart' => $kinds[$kind]['id'], 'lehrperson' => $teachers[$teacher],
                'raum' => $rooms[$room], 'semester' => $hs, 'wochentag' => $day, 'uhrzeit' => $time, 'max_teilnehmer' => $max]);
            $courses[$name] = ['id' => $id, 'minutes' => $kinds[$kind]['minutes'], 'teacher' => $teachers[$teacher], 'members' => []];

            foreach ($members as $m) {
                [$student, $discount] = is_array($m) ? $m : [$m, null];
                $insert('anmeldungen', ['schueler' => $students[$student]['id'], 'kurs' => $id, 'datum' => '2026-06-15', 'status' => 'aktiv', 'rabatt_prozent' => $discount]);
                $courses[$name]['members'][] = [$student, $discount];
            }
        }

        // Warteliste und Abmeldung
        $insert('anmeldungen', ['schueler' => $students['Jan Baumann']['id'], 'kurs' => $courses['Klavier Mia']['id'], 'datum' => '2026-07-01', 'status' => 'warteliste']);
        $insert('anmeldungen', ['schueler' => $students['Julia Brunner']['id'], 'kurs' => $courses['Gesang Lara']['id'], 'datum' => '2026-06-10', 'status' => 'abgemeldet']);

        // Zehn Lektionen je Kurs ab Semesterbeginn, ohne Ferienwochen, mit Präsenzen (gleichmässig gestreut, ohne Zufall)
        $themes = ['Tonleitern', 'Neues Stück', 'Rhythmusübungen', 'Repertoire', 'Technik', 'Vorbereitung Vorspiel', 'Improvisation', 'Notenlesen', 'Wiederholung', 'Ensemblespiel'];
        $offset = ['mo' => 0, 'di' => 1, 'mi' => 2, 'do' => 3, 'fr' => 4, 'sa' => 5];
        $n = 0;

        foreach ($courses as $name => $course) {
            $day = DynamicRecord::forTableName('musikschule_kurse')->newQuery()->findOrFail($course['id'])->wochentag;

            for ($week = 0, $date = '2026-08-17'; $week < 10; $date = date('Y-m-d', strtotime($date.' +7 days'))) {
                $date = date('Y-m-d', strtotime('+'.$offset[$day].' days', (int) strtotime('monday this week', (int) strtotime($date))));

                if (self::inHolidays($date)) {
                    continue;
                }

                $lesson = $insert('lektionen', ['kurs' => $course['id'], 'lehrperson' => $course['teacher'], 'datum' => $date, 'dauer_minuten' => $course['minutes'], 'thema' => $themes[($week + $n) % 10]]);

                foreach ($course['members'] as $j => [$student]) {
                    $k = ($week * 7 + $j * 3 + $n) % 17;
                    $insert('praesenzen', ['lektion' => $lesson, 'schueler' => $students[$student]['id'], 'status' => $k === 0 ? 'unentschuldigt' : ($k < 3 ? 'entschuldigt' : 'anwesend')]);
                }

                $week++;
            }

            $n++;
        }

        // Instrumente und Mietverträge
        $instruments = [];

        foreach ([['I-001', 'Konzertgitarre 1/2', 'gut', '25.00'], ['I-002', 'Konzertgitarre 3/4', 'gut', '25.00'], ['I-003', 'Konzertgitarre 4/4', 'neu', '30.00'],
            ['I-004', 'Violine 1/2', 'gut', '35.00'], ['I-005', 'Violine 3/4', 'gebraucht', '35.00'], ['I-006', 'Querflöte', 'gut', '40.00'],
            ['I-007', 'Digitalpiano', 'neu', '45.00'], ['I-008', 'Übungs-Drumset', 'gebraucht', '40.00'], ['I-009', 'Konzertgitarre 1/2', 'reparatur', '25.00'],
            ['I-010', 'Violine 1/4', 'ausgemustert', null]] as [$no, $name, $state, $rent]) {
            $instruments[$no] = $insert('instrumente', ['inventarnummer' => $no, 'bezeichnung' => $name, 'zustand' => $state, 'mietpreis' => $rent]);
        }

        $rentals = [];

        foreach ([['M-2026-01', 'I-001', 'Noah Meier', '2024-08-19', null, '25.00', '200.00'], ['M-2026-02', 'I-004', 'Sofia Schmid', '2025-02-17', null, '35.00', '300.00'],
            ['M-2026-03', 'I-005', 'Aylin Yilmaz', '2024-02-19', null, '35.00', '300.00'], ['M-2026-04', 'I-006', 'Lea Suter', '2025-08-18', null, '40.00', '300.00'],
            ['M-2026-05', 'I-008', 'Tim Wyss', '2024-08-19', null, '40.00', '300.00'], ['M-2025-01', 'I-002', 'Julia Brunner', '2021-08-16', '2026-01-31', '25.00', '200.00']] as [$no, $instrument, $student, $from, $to, $rent, $deposit]) {
            $rentals[$student] = $insert('mietvertraege', ['vertragsnummer' => $no, 'instrument' => $instruments[$instrument], 'schueler' => $students[$student]['id'],
                'beginn' => $from, 'ende' => $to, 'mietpreis' => $rent, 'kaution' => $deposit, 'kaution_zurueck' => $to !== null]);
        }

        // Rechnungen HS 2026 je Rechnungsempfänger: Kurse (Preis aus dem Tarif des Kurses) und Miete (6 Monate)
        $bills = [];

        foreach ($courses as $name => $course) {
            foreach ($course['members'] as [$student, $discount]) {
                $payer = $students[$student]['payer'];
                $key = json_encode($payer);
                $bills[$key] ??= ['payer' => $payer, 'lines' => []];
                $bills[$key]['lines'][] = ['art' => 'kurs', 'text' => $name.' ('.$student.')', 'menge' => '1', 'kurs' => $course['id'], 'rabatt_prozent' => $discount];
            }
        }

        foreach ($rentals as $student => $rental) {
            if ($student === 'Julia Brunner') {
                continue;
            }

            $payer = $students[$student]['payer'];
            $bills[json_encode($payer)] ??= ['payer' => $payer, 'lines' => []];
            $bills[json_encode($payer)]['lines'][] = ['art' => 'miete', 'text' => 'Instrumentenmiete Aug.–Jan. ('.$student.')', 'menge' => '6', 'mietvertrag' => $rental,
                'einzelpreis' => (string) DynamicRecord::forTableName('musikschule_mietvertraege')->newQuery()->findOrFail($rental)->mietpreis];
        }

        $number = 0;

        foreach ($bills as $bill) {
            $number++;
            $id = $insert('rechnungen', ['rechnungsnummer' => sprintf('R-2026-%03d', $number), 'datum' => '2026-09-01', 'faellig_am' => '2026-09-30',
                'status' => $number === 7 ? 'storniert' : 'versendet', 'semester' => $hs, ...$bill['payer']]);

            foreach ($bill['lines'] as $line) {
                $insert('rechnungspositionen', ['rechnung' => $id, ...$line]);
            }

            if ($number === 7) {
                continue;
            }

            // Zahlungen: meist vollständig, einige in Raten, ein paar noch offen.
            $total = (float) DynamicRecord::forTableName('musikschule_rechnungen')->newQuery()->findOrFail($id)->summe;

            if ($number % 6 === 0) {
                continue;
            }

            if ($number % 5 === 0) {
                $insert('zahlungen', ['rechnung' => $id, 'datum' => '2026-09-28', 'betrag' => number_format(round($total / 2, 2), 2, '.', ''), 'zahlungsart' => 'ueberweisung', 'bemerkung' => 'Rate 1/2']);

                continue;
            }

            $insert('zahlungen', ['rechnung' => $id, 'datum' => date('Y-m-d', strtotime('2026-09-05 +'.($number * 2).' days')), 'betrag' => number_format($total, 2, '.', ''),
                'zahlungsart' => ['ueberweisung', 'twint', 'karte', 'ueberweisung'][$number % 4]]);
        }

        // Konzerte und Vorspiele
        foreach ([
            ['Herbstvorspiel', 'vorspiel', '2026-11-14 16:00', 'Saal', [['Lena Meier', 'Romanze', 'Anonym', 'Gitarre'], ['Mia Huber', 'Für Elise', 'L. v. Beethoven', 'Klavier'], ['Sofia Schmid', 'Menuett', 'J. S. Bach', 'Violine'], ['Tim Wyss', 'Rock Groove', null, 'Schlagzeug']]],
            ['Weihnachtskonzert', 'konzert', '2026-12-13 17:00', 'Reformierte Kirche', [['Kinderchor', 'Stille Nacht', 'F. X. Gruber', 'Gesang'], ['Emma Steiner', 'Nocturne op. 9 Nr. 2', 'F. Chopin', 'Klavier'], ['Lea Suter', 'Syrinx', 'C. Debussy', 'Querflöte']]],
            ['Frühlingskonzert', 'konzert', '2027-05-08 18:00', 'Saal', []],
        ] as [$title, $kind, $date, $place, $acts]) {
            $event = $insert('veranstaltungen', ['titel' => $title, 'art' => $kind, 'datum' => $date.':00', 'ort' => $place]);

            foreach ($acts as $i => [$who, $piece, $composer, $instrument]) {
                $insert('auftritte', ['veranstaltung' => $event, 'schueler' => $students[$who]['id'] ?? $students['Nina Wyss']['id'], 'stueck' => $piece, 'komponist' => $composer,
                    'instrument' => $instrument, 'reihenfolge' => $i + 1]);
            }
        }

        // Ein begonnenes zweites Semester: zwei Kurse mit ersten Anmeldungen.
        $insert('anmeldungen', ['schueler' => $students['Jan Baumann']['id'], 'datum' => '2026-12-01', 'status' => 'aktiv',
            'kurs' => $insert('kurse', ['bezeichnung' => 'Klavier Jan', 'fach' => $subjects['Klavier'], 'kursart' => $kinds['Einzel 30']['id'], 'lehrperson' => $teachers['Moretti'],
                'raum' => $rooms['Raum 1'], 'semester' => $fs, 'wochentag' => 'mo', 'uhrzeit' => '17:30', 'max_teilnehmer' => 1])]);
        $insert('kurse', ['bezeichnung' => 'Workshop Songwriting', 'fach' => $subjects['Gesang'], 'kursart' => $kinds['Workshop']['id'], 'lehrperson' => $teachers['Keller'],
            'raum' => $rooms['Bandraum'], 'semester' => $fs, 'wochentag' => 'sa', 'uhrzeit' => '13:00', 'max_teilnehmer' => 8]);
    }

    private static function inHolidays(string $date): bool
    {
        foreach (self::HOLIDAYS as [, $from, $to]) {
            if ($date >= $from && $date <= $to) {
                return true;
            }
        }

        return false;
    }

    /** Für Tests und Prüfungen: die App, falls installiert. */
    public static function installedApp(): ?App
    {
        return CustomTable::query()->where('name', 'musikschule_faecher')->first()?->app;
    }
}
