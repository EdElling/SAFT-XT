<?php

declare(strict_types=1);

// Plages de numéros (B-135) : type de champ « Plage de numéros » dans le concepteur de tables.
return [
    'fields' => [
        'pattern' => 'Modèle',
        'reset' => 'Redémarrage',
        'date_field' => 'Date de référence',
        'fiscal_start' => 'L’exercice commence en',
        'start' => 'Valeur de départ',
    ],
    'form' => [
        'section' => 'Plage de numéros',
        'pattern_help' => 'Texte fixe et espaces réservés, p. ex. R-{YYYY}-{0000}. Le numéro courant a autant de chiffres que de zéros.',
        'insert' => 'Insérer un élément',
        'insert_placeholder' => 'Choisir un espace réservé …',
        'date_created' => 'Date de création',
        'date_field_help' => 'Détermine l’année et le mois du numéro ainsi que le redémarrage. Seuls les champs date obligatoires sont proposés.',
        'start_help' => 'Premier numéro par période.',
        'next' => 'Prochain numéro : :number',
        'assigned_on_save' => 'attribué à l’enregistrement',
    ],
    'resets' => [
        'never' => 'jamais',
        'yearly' => 'annuel',
        'monthly' => 'mensuel',
    ],
    'months' => [
        1 => 'janvier',
        2 => 'février',
        3 => 'mars',
        4 => 'avril',
        5 => 'mai',
        6 => 'juin',
        7 => 'juillet',
        8 => 'août',
        9 => 'septembre',
        10 => 'octobre',
        11 => 'novembre',
        12 => 'décembre',
    ],
    'blocks' => [
        'year4' => 'année, quatre chiffres',
        'year2' => 'année, deux chiffres',
        'month' => 'mois',
        'counter' => 'numéro courant, complété par des zéros',
        'counter_plain' => 'numéro courant, sans zéros',
    ],
    'errors' => [
        'reset' => 'Redémarrage inconnu « :reset » (jamais, annuel ou mensuel).',
        'fiscal_start' => 'L’exercice commence un mois de 1 à 12.',
        'start' => 'La valeur de départ est d’au moins 1.',
        'counter' => 'Le modèle « :pattern » doit contenir exactement un espace réservé pour le numéro courant, p. ex. {0000} ou {#}.',
        'needs_yearly' => 'Avec un redémarrage annuel, le modèle « :pattern » doit contenir une année ({YYYY} ou {YY}), sinon des numéros se répéteraient.',
        'needs_monthly' => 'Avec un redémarrage mensuel, le modèle « :pattern » doit contenir l’année et le mois ({YYYY} ou {YY} et {MM}), sinon des numéros se répéteraient.',
        'too_long' => 'Les numéros selon le modèle « :pattern » dépasseraient :max caractères.',
        'characters' => 'En dehors des espaces réservés, le modèle « :pattern » ne peut contenir que des lettres, des chiffres, des espaces et - / . _',
        'placeholder' => 'Espace réservé inconnu :placeholder. Possibles : {YYYY}, {YY}, {MM}, {0000} et {#}.',
        'date_field' => 'La date de référence « :field » n’est pas un champ date ou date et heure de cette table.',
        'date_field_required' => 'La date de référence « :field » doit être un champ obligatoire, afin que chaque numéro ait une date.',
        'date_field_in_use' => 'Le champ « :name » est la date de référence de la plage de numéros « :field » et reste donc un champ date actif et obligatoire.',
        'date_missing' => 'La date de référence « :date » manque pour le numéro « :field ».',
        'given' => 'SAFT attribue le numéro « :field » à l’enregistrement ; il ne peut pas être imposé.',
        'immutable' => 'Le numéro « :field » est attribué et ne peut plus être modifié.',
        'type_change' => 'Le type de « :name » ne peut passer de ou vers « Plage de numéros » que tant qu’aucun enregistrement n’a de valeur dans ce champ (corbeille comprise).',
    ],
];
