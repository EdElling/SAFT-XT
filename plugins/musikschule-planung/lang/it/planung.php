<?php

declare(strict_types=1);

return [
    'plugin' => 'Scuola di musica: pianificazione',
    'description' => 'Orario per docente, aula o allievo, controllo dei conflitti al salvataggio di un corso e riporto del semestre (esempio per la demo «Scuola di musica»).',
    'permissions' => [
        'timetable' => 'Vedere l’orario',
        'rollover' => 'Riportare il semestre',
    ],
    'days' => ['mo' => 'lunedì', 'di' => 'martedì', 'mi' => 'mercoledì', 'do' => 'giovedì', 'fr' => 'venerdì', 'sa' => 'sabato', 'so' => 'domenica'],
    'conflict' => [
        'teacher' => ':name insegna già «:course» il :day dalle :from alle :to.',
        'room' => ':name è già occupata da «:course» il :day dalle :from alle :to.',
    ],
    'capacity' => [
        'title' => 'Aula troppo piccola',
        'body' => '«:course» ammette :max partecipanti, :room ha solo :places posti.',
    ],
    'rollover' => [
        'action' => 'Riportare il semestre',
        'heading' => 'Riprendere i corsi nel semestre successivo',
        'submit' => 'Riportare',
        'source' => 'Dal semestre',
        'target' => 'Al semestre',
        'keep_schedule' => 'Riprendere giorno, ora e aula',
        'keep_schedule_help' => 'Altrimenti restano vuoti e vengono pianificati nel nuovo semestre.',
        'enrolments' => 'Riprendere le iscrizioni attive',
        'enrolments_help' => 'In lista d’attesa – confermatele impostando lo stato su «attivo».',
        'new_tariff' => 'Tariffa dal tipo di corso',
        'new_tariff_help' => 'Altrimenti vale la tariffa del corso precedente.',
        'choose' => 'Scegliere origine e destinazione.',
        'same' => 'Origine e destinazione devono essere semestri diversi.',
        'preview' => 'Verranno ripresi :courses corsi e :enrolments iscrizioni.',
        'preview_skipped' => ':count corsi esistono già nella destinazione e vengono saltati: :names.',
        'done' => ':courses corsi e :enrolments iscrizioni ripresi in :target.',
        'failed' => 'Riporto impossibile – non è stato ripreso nulla.',
        'rights' => 'Manca il diritto di creare in: :tables.',
    ],
    'timetable' => [
        'title' => 'Orario',
        'semester' => 'Semestre',
        'view' => 'Vista',
        'views' => ['teacher' => 'Docente', 'room' => 'Aula', 'student' => 'Allievo'],
        'choice' => ['lehrperson' => 'Docente', 'raum' => 'Aula', 'schueler' => 'Allievo'],
        'tooltip' => ':count posti su :max occupati',
        'conflicts' => ':count corsi si sovrappongono (bordati di rosso).',
        'empty' => 'Nessun corso in questo semestre.',
        'unscheduled' => 'Senza giorno o ora',
        'print' => 'Stampa',
        'unavailable_title' => 'App Scuola di musica incompleta',
        'unavailable' => 'Mancano:',
        'no_rights' => 'Non è consentito vedere i corsi.',
    ],
];
