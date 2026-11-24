<?php

declare(strict_types=1);

return [
    'model' => 'Travail',
    'plural' => 'Travaux',
    'status' => [
        'queued' => 'en attente',
        'running' => 'en cours',
        'done' => 'terminé',
        'failed' => 'échoué',
        'cancelled' => 'annulé',
    ],
    'types' => [
        'recompute' => 'Recalculer les champs calculés',
        'export' => 'Exporter',
        'documents' => 'Créer des documents',
        'record_import' => 'Importer des données',
        'snapshot_refresh' => 'Prendre un état des données',
        'app_designer_turn' => 'Concevoir une app',
        'app_designer_create' => 'Créer une app',
        'app_designer_reports' => 'Créer les rapports de l’app',
    ],
    'export' => [
        'done' => '1 ligne exportée.|:count lignes exportées.',
        'table_missing' => 'La table n’existe plus.',
    ],
    'import' => [
        'table_missing' => 'La table n’existe plus.',
        'file_missing' => 'Le fichier téléversé n’est plus disponible ; veuillez relancer l’importation.',
    ],
    'recompute' => [
        'all_tables' => 'toutes les tables avec des champs calculés',
    ],
    'cancelled' => 'Annulé.',
    'aborted_repeatedly' => 'Interrompu plusieurs fois sans aboutir (p. ex. limite de temps du serveur dépassée). Le travail a été arrêté.',
    'failed_generic' => 'Le travail a échoué (référence :reference). Les détails figurent dans le journal système.',
    'plugin_inactive' => 'Le plugin « :plugin » est en sommeil ; le travail n’a pas été exécuté.',
    'started' => 'Travail démarré (:id) ; le planificateur l’exécute en arrière-plan.',
    'my_work' => 'Mes travaux',
    'empty' => 'Aucun travail',
    'system' => 'Système',
    'tabs' => [
        'all' => 'Tous',
        'mine' => 'Les miens',
    ],
    'sections' => [
        'details' => 'Informations',
    ],
    'fields' => [
        'type' => 'Type',
        'description' => 'Description',
        'user' => 'Démarré par',
        'app' => 'App',
        'status' => 'Statut',
        'progress' => 'Progression',
        'created' => 'Démarré',
        'finished' => 'Terminé',
        'duration' => 'Durée',
        'message' => 'Message',
    ],
    'actions' => [
        'download' => 'Télécharger le résultat',
        'cancel' => 'Annuler',
        'cancel_heading' => 'Annuler le travail ?',
        'cancel_requested' => 'Le travail est en cours d’annulation.',
        'cancelling' => 'Annulation …',
    ],
    'progress' => [
        'driving' => 'S’exécute tant que cette page est ouverte. Si vous la fermez, le travail se poursuit en arrière-plan et vous recevez une notification à la fin.',
        'background' => 'S’exécute en arrière-plan.',
        'expires' => 'disponible jusqu’au :time',
    ],
    'notifications' => [
        'done' => '« :work » est terminé',
        'failed' => '« :work » a échoué',
        'open' => 'Ouvrir',
    ],
    'widget' => [
        'stuck' => '1 travail sans progression depuis plus de :minutes minutes (le planificateur tourne-t-il ?)|:count travaux sans progression depuis plus de :minutes minutes (le planificateur tourne-t-il ?)',
        'failed' => '1 travail a échoué au cours des dernières 24 heures|:count travaux ont échoué au cours des dernières 24 heures',
    ],
];
