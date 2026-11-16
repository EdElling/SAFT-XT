<?php

declare(strict_types=1);

return [
    'model' => 'utilisateur',
    'plural' => 'utilisateurs',
    'sso' => [
        'title' => 'Liaisons SSO',
        'provider' => 'Fournisseur d’identité',
        'provider_email' => 'E-mail auprès de l’IdP',
        'provider_user_id' => 'ID utilisateur IdP',
        'linked_since' => 'Lié depuis',
        'unlink' => 'Dissocier',
        'unlink_heading' => 'Dissocier le compte SSO',
        'unlink_description' => 'L’utilisateur sera de nouveau associé lors de la prochaine connexion SSO (si l’association par e-mail ou la création automatique le permet).',
    ],
    'form' => [
        'master_data' => 'Données de base',
        'password' => 'Mot de passe',
        'password_keep' => 'Laisser vide pour conserver le mot de passe actuel.',
        'password_sso' => 'Facultatif : en mode SSO, le mot de passe ne sert qu’à la connexion d’urgence.',
        'password_confirmation' => 'Confirmer le mot de passe',
        'inactive_help' => 'Les utilisateurs inactifs ne peuvent pas se connecter.',
        'super_admin_only' => 'Seuls les super-administrateurs peuvent attribuer le rôle « super-admin ».',
    ],
    'table' => [
        'last_login' => 'Dernière connexion',
        'without_role' => 'Sans rôle',
        'sso_linked' => 'Liaison SSO',
    ],
    'delete' => [
        'soft' => 'Le compte est placé dans la corbeille. La personne ne peut plus se connecter, pas même par authentification unique, et n’obtient pas de nouveau compte de cette manière. Le compte peut être restauré ; son nom reste visible dans le journal des modifications.',
        'force' => 'Le compte est supprimé définitivement, ainsi que ses liaisons SSO. Si la personne se connecte plus tard par authentification unique, un nouveau compte peut être créé (provisionnement automatique ou liaison par e-mail). Le journal des modifications n’affiche alors plus que le numéro de l’utilisateur au lieu du nom.',
    ],
];
