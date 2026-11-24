<?php

declare(strict_types=1);

return [
    'title' => 'États des données',
    'description' => 'Copies immuables de cette table pour les rapports. Les rapports utilisent toujours l’état complet le plus récent. Les suppressions dans la source ne s’appliquent qu’avec l’état suivant ; la conservation supprime les états plus anciens.',
    'refresh' => 'Actualiser maintenant',
    'refreshed' => 'État pris : :rows lignes',
    'started' => 'L’état des données est pris en arrière-plan ; la liste l’affichera dès qu’il sera complet.',
    'retention' => 'Conservation',
    'keep' => 'Conserver les derniers états',
    'keep_help' => 'Au moins 1 ; l’état le plus récent est toujours conservé.',
    'max_days' => 'Au plus jours',
    'max_days_help' => 'Vide = sans limite.',
    'retention_saved' => 'Conservation enregistrée',
    'delete' => 'Supprimer',
    'delete_all' => 'Supprimer tous les états',
    'delete_all_description' => 'Tous les états de cette table sont supprimés. Les rapports ne fonctionnent à nouveau qu’après la prochaine actualisation.',
    'deleted' => 'État supprimé',
    'deleted_all' => 'Tous les états supprimés',
    'time' => 'Date et heure',
    'rows' => 'Lignes',
    'status' => 'Statut',
    'by' => 'Déclenché par',
    'automatic' => 'automatique',
    'message' => 'Message',
    'statuses' => [
        'running' => 'en cours',
        'complete' => 'complet',
        'failed' => 'échoué',
    ],
    'none' => 'Aucun état pour l’instant',
    'as_of' => 'État des données : :table (:connection) du :time',
    'errors' => [
        'failed' => 'L’état n’a pas pu être pris ; le précédent reste valable. Détails dans le journal de l’application.',
        'too_large' => 'La table compte plus de :max lignes – trop grande pour un état.',
        'not_external' => 'Les états n’existent que pour les tables connectées.',
        'busy' => 'Cette table est en cours d’actualisation. Veuillez réessayer plus tard.',
        'aborted' => 'Interrompu (le processus a été arrêté).',
    ],
];
