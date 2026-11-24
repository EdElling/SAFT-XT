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
    ],
    'recompute' => [
        'all_tables' => 'toutes les tables avec des champs calculés',
    ],
    'cancelled' => 'Annulé.',
    'aborted_repeatedly' => 'Interrompu plusieurs fois sans aboutir (p. ex. limite de temps du serveur dépassée). Le travail a été arrêté.',
    'failed_generic' => 'Le travail a échoué (référence :reference). Les détails figurent dans le journal système.',
    'plugin_inactive' => 'Le plugin « :plugin » est en sommeil ; le travail n’a pas été exécuté.',
    'started' => 'Travail démarré (:id) ; le planificateur l’exécute en arrière-plan.',
];
