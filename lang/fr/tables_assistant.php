<?php

declare(strict_types=1);

return [
    'title' => 'Décrire la table (assistant IA)',
    'privacy' => 'Votre description et la structure des tables du concepteur (noms, libellés, types de champs) sont transmises à :provider – aucun enregistrement.',
    'placeholder' => 'p. ex. « Membres d’une association avec date d’entrée, catégorie de cotisation (actif, passif, junior) et e-mail »',
    'placeholder_followup' => 'Décrire une modification, p. ex. « ajouter un champ Remarques » ou « catégorie obligatoire »',
    'applied_form' => 'Proposition reprise – veuillez vérifier les noms, champs et types. La table n’est créée qu’avec « Créer ».',
    'edit_action' => 'Adapter avec l’IA',
    'edit_heading' => 'Adapter la table avec l’assistant IA',
    'edit_prompt' => 'Que faut-il modifier ?',
    'edit_placeholder' => 'p. ex. « Ajouter la date d’entrée et de sortie », « e-mail unique et obligatoire » ou « relier à la table Associations »',
    'edit_help' => 'Sont permis : nouveaux champs, libellés, obligatoire/unique/recherchable/triable, choix supplémentaires et champs affichés des relations. Changer le type, renommer et supprimer restent manuels.',
    'preview_heading' => 'Modifications proposées',
    'preview_intro' => 'L’assistant propose :',
    'apply' => 'Appliquer',
    'applied_all' => 'Toutes les modifications ont été appliquées.',
    'applied_some' => 'Tout n’a pas été appliqué :',
    'summary' => [
        'add' => 'Nouveau : :field (:type)',
        'change' => 'Modifier : :field – :what',
        'on' => ':what activé',
        'off' => ':what désactivé',
        'label' => 'libellé « :label »',
        'choices' => ':count choix supplémentaires',
        'display' => 'affichage : :fields',
    ],
    'flags' => [
        'required' => 'obligatoire',
        'unique' => 'unique',
        'searchable' => 'recherchable',
        'sortable' => 'triable',
    ],
    'applied' => [
        'added' => 'Champ :field ajouté.',
        'changed' => 'Champ :field modifié.',
    ],
    'errors' => [
        'invalid' => 'Aucune proposition valide : :errors. Veuillez préciser la description ou compléter à la main.',
        'name_taken' => 'Le nom « :name » est déjà utilisé (:owner).',
        'menu_group' => 'Le groupe de menu :id n’existe pas.',
        'no_fields' => 'La table nécessite au moins un champ.',
        'duplicate_field' => 'Le champ « :name » apparaît deux fois.',
        'field' => 'Champ « :name » : :error',
        'no_changes' => 'Aucune modification proposée.',
        'field_exists' => 'Le champ « :name » existe déjà.',
        'unknown_field' => 'Le champ « :name » n’existe pas.',
    ],
];
