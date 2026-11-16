<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Connection;

/**
 * Gemeinsame Demodaten der Forschungsdatenbank (PostgreSQL-, MySQL-/MariaDB- und SQL-Server-Fassung): gleiche
 * Organisationen, Personen, Projekte, Publikationen und Autoren.
 *
 * @property-read Connection $db
 */
trait ResearchDemoData
{
    /** Voll qualifizierter Name einer Demotabelle. */
    abstract protected function qualified(string $table): string;

    /**
     * Neue Publikation; gibt ihren (UUID-)Schlüssel zurück. PostgreSQL vergibt ihn selbst.
     *
     * @param  array<string, mixed>  $row
     */
    protected function insertPublication(array $row): string
    {
        return (string) $this->db->table($this->qualified('publikationen'))->insertGetId($row);
    }

    /** Zeitpunkt des Importprotokolls mit Zeitzone (MariaDB: datetime ohne Zeitzonenangabe). */
    protected function importTime(string $local): string
    {
        return $local.'+01:00';
    }

    private function seed(): void
    {
        $organisations = [];

        foreach ([
            ['Universität Zürich', 'CH', 'hochschule', '1833-04-29', 'https://www.uzh.ch', true],
            ['ETH Zürich', 'CH', 'hochschule', '1855-02-07', 'https://ethz.ch', true],
            ['Universität Wien', 'AT', 'hochschule', '1365-03-12', 'https://www.univie.ac.at', true],
            ['Institut für Angewandte Datenwissenschaft', 'DE', 'forschungsinstitut', '1990-01-01', null, true],
            ['Alpenlabor AG', 'CH', 'unternehmen', '2011-06-15', null, true],
            ['Datenwerk GmbH', 'DE', 'unternehmen', '2004-09-01', null, true],
            ['Bundesamt für Statistik', 'CH', 'behoerde', '1860-01-01', 'https://www.bfs.admin.ch', true],
            ['Nordlicht Analytics AB', 'SE', 'unternehmen', '2016-03-20', null, false],
        ] as [$name, $land, $typ, $gegruendet, $webseite, $aktiv]) {
            $organisations[] = $this->db->table($this->qualified('organisationen'))->insertGetId(compact('name', 'land', 'typ', 'gegruendet', 'webseite', 'aktiv'));
        }

        $people = [];
        $names = [
            ['Anna', 'Keller'], ['Bruno', 'Meier'], ['Chiara', 'Rossi'], ['David', 'Huber'], ['Eva', 'Schmid'],
            ['Felix', 'Weber'], ['Gina', 'Brunner'], ['Hans', 'Frei'], ['Ilaria', 'Conti'], ['Jonas', 'Baumann'],
            ['Katrin', 'Graf'], ['Luca', 'Moretti'], ['Mara', 'Steiner'], ['Nils', 'Lindqvist'], ['Olivia', 'Fischer'],
            ['Paul', 'Zimmermann'], ['Rahel', 'Bühler'], ['Simon', 'Wyss'], ['Tanja', 'Kovač'], ['Urs', 'Gerber'],
        ];

        foreach ($names as $i => [$vorname, $nachname]) {
            $people[] = $this->db->table($this->qualified('personen'))->insertGetId([
                'vorname' => $vorname,
                'nachname' => $nachname,
                'email' => mb_strtolower(str_replace(['ü', 'č'], ['ue', 'c'], "{$vorname}.{$nachname}")).'@example.org',
                'orcid' => $i % 3 === 0 ? sprintf('0000-0002-%04d-%04d', 1000 + $i * 37, 2000 + $i * 53) : null,
                'geburtsdatum' => sprintf('%04d-%02d-%02d', 1960 + ($i * 7) % 35, 1 + $i % 12, 1 + ($i * 5) % 28),
                // Person 20 ohne Arbeitgeber.
                'arbeitgeber_id' => $i === 19 ? null : $organisations[$i % count($organisations)],
            ]);
        }

        $projects = [];
        $titles = [
            'Gletscherschmelze und Wasserhaushalt', 'Mehrsprachige Verwaltungsdaten', 'Sensornetze im Alpenraum',
            'Offene Daten in der Gemeinde', 'Maschinelles Lernen für Biodiversität', 'Datenqualität in Registern',
            'Digitale Archive des 19. Jahrhunderts', 'Energieflüsse in Quartieren', 'Verkehrsmodelle für Pendler',
            'Gesundheitsdaten und Datenschutz', 'Klimadaten für Landwirtschaft', 'Bürgerbeteiligung online',
        ];
        $statuses = ['geplant', 'laufend', 'laufend', 'abgeschlossen', 'laufend', 'abgebrochen'];

        foreach ($titles as $i => $titel) {
            $status = $statuses[$i % count($statuses)];
            $beginn = sprintf('%04d-%02d-01', 2019 + $i % 6, 1 + ($i * 3) % 12);
            $projects[] = $this->db->table($this->qualified('projekte'))->insertGetId([
                'kurzname' => sprintf('P-%02d', $i + 1),
                'titel' => $titel,
                'beginn' => $beginn,
                'ende' => in_array($status, ['abgeschlossen', 'abgebrochen'], true) ? date('Y-m-d', strtotime($beginn.' +2 years')) : null,
                'budget' => 50000 + $i * 37500.5,
                'status' => $status,
                'projektleiter_id' => $people[($i * 3) % count($people)],
                'partner_id' => $i % 4 === 3 ? null : $organisations[($i + 2) % count($organisations)],
                'metadaten' => json_encode(['foerderung' => $i % 2 === 0 ? 'SNF' : 'EU', 'schlagworte' => ['Daten', 'Forschung']]),
            ]);
        }

        $types = ['artikel', 'konferenz', 'buchkapitel', 'bericht'];

        for ($i = 0; $i < 25; $i++) {
            $row = [
                'titel' => 'Studie '.($i + 1).': '.$titles[$i % count($titles)],
                'jahr' => 2019 + $i % 7,
                'doi' => $i % 5 === 4 ? null : sprintf('10.5555/saft.%04d', 1000 + $i),
                'typ' => $types[$i % count($types)],
                'open_access' => $i % 3 !== 1,
                // Jede achte Publikation ohne Projekt.
                'projekt_id' => $i % 8 === 7 ? null : $projects[$i % count($projects)],
            ];
            $publication = $this->insertPublication($row);

            // 1 bis 4 Autoren, verschiedene Personen.
            for ($author = 0; $author <= $i % 4; $author++) {
                $this->db->table($this->qualified('publikation_autoren'))->insert([
                    'publikation_id' => $publication,
                    'person_id' => $people[($i * 2 + $author * 5) % count($people)],
                    'reihenfolge' => $author + 1,
                ]);
            }
        }

        foreach (['AT' => 'Österreich', 'CH' => 'Schweiz', 'DE' => 'Deutschland', 'SE' => 'Schweden'] as $code => $name) {
            $this->db->table($this->qualified('laender'))->insert(['code' => $code, 'name' => $name]);
        }

        foreach (range(1, 5) as $i) {
            $this->db->table($this->qualified('importprotokoll'))->insert([
                'zeitpunkt' => $this->importTime(sprintf('2026-10-%02d 0%d:15:00', $i * 3, $i)),
                'quelle' => $i % 2 === 0 ? 'ORCID-Abgleich' : 'Publikationsimport',
                'zeilen' => $i * 42,
                'meldung' => $i === 4 ? '3 Zeilen übersprungen (DOI doppelt)' : null,
            ]);
        }
    }
}
