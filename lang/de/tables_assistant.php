<?php

declare(strict_types=1);

return [
    'title' => 'Tabelle beschreiben (KI-Assistent)',
    'privacy' => 'Ihre Beschreibung und der Aufbau der Tabellen des Table-Designers (Namen, Bezeichnungen, Feldtypen) werden an :provider übermittelt – keine Datensätze.',
    'placeholder' => 'z. B. „Mitglieder eines Vereins mit Eintrittsdatum, Beitragskategorie (Aktiv, Passiv, Junior) und E-Mail“',
    'placeholder_followup' => 'Änderung beschreiben, z. B. „zusätzlich ein Feld Bemerkungen“ oder „Kategorie als Pflichtfeld“',
    'applied_form' => 'Vorschlag übernommen – bitte Namen, Felder und Typen prüfen. Angelegt wird erst mit „Erstellen“.',
    'edit_action' => 'Mit KI anpassen',
    'edit_heading' => 'Tabelle mit dem KI-Assistenten anpassen',
    'edit_prompt' => 'Was soll sich ändern?',
    'edit_placeholder' => 'z. B. „Ergänze Eintrittsdatum und Austrittsdatum“, „E-Mail eindeutig und Pflicht“ oder „mit der Tabelle Vereine verknüpfen“',
    'edit_help' => 'Erlaubt sind neue Felder, Bezeichnungen, Pflicht/eindeutig/durchsuchbar/sortierbar, weitere Auswahloptionen und Anzeigefelder von Verknüpfungen. Typ ändern, umbenennen und löschen bleibt Handarbeit.',
    'preview_heading' => 'Vorgeschlagene Änderungen',
    'preview_intro' => 'Der Assistent schlägt vor:',
    'apply' => 'Übernehmen',
    'applied_all' => 'Alle Änderungen übernommen.',
    'applied_some' => 'Nicht alles übernommen:',
    'summary' => [
        'add' => 'Neu: :field (:type)',
        'change' => 'Ändern: :field – :what',
        'on' => ':what an',
        'off' => ':what aus',
        'label' => 'Bezeichnung „:label“',
        'choices' => ':count weitere Auswahloptionen',
        'display' => 'Anzeige: :fields',
    ],
    'flags' => [
        'required' => 'Pflicht',
        'unique' => 'eindeutig',
        'searchable' => 'durchsuchbar',
        'sortable' => 'sortierbar',
    ],
    'applied' => [
        'added' => 'Feld :field ergänzt.',
        'changed' => 'Feld :field geändert.',
    ],
    'errors' => [
        'invalid' => 'Kein gültiger Vorschlag: :errors. Bitte genauer beschreiben oder von Hand ergänzen.',
        'name_taken' => 'Der Name „:name“ ist schon vergeben (:owner).',
        'menu_group' => 'Menügruppe :id gibt es nicht.',
        'no_fields' => 'Die Tabelle braucht mindestens ein Feld.',
        'duplicate_field' => 'Feld „:name“ kommt doppelt vor.',
        'field' => 'Feld „:name“: :error',
        'no_changes' => 'Keine Änderung vorgeschlagen.',
        'field_exists' => 'Feld „:name“ gibt es schon.',
        'unknown_field' => 'Feld „:name“ gibt es nicht.',
    ],
];
