<?php

declare(strict_types=1);

return [
    'modes' => [
        'local' => [
            'label' => 'Local',
            'description' => 'Connexion uniquement avec e-mail et mot de passe.',
        ],
        'sso' => [
            'label' => 'Authentification unique (SSO)',
            'description' => 'Connexion uniquement via un fournisseur d’identité.',
        ],
        'hybrid' => [
            'label' => 'Hybride',
            'description' => 'Connexion par mot de passe ou par authentification unique.',
        ],
    ],
    'types' => [
        'entra' => 'Microsoft Entra ID',
        'saml' => 'SAML 2.0',
    ],
    'login' => [
        'sso_with' => 'Se connecter avec :name',
        'sso_divider' => 'ou',
        'break_glass' => 'Connexion d’urgence pour les administrateurs',
        'break_glass_hint' => 'Réservé aux super-administrateurs, si la connexion SSO ne fonctionne pas.',
        'no_method' => 'Aucune méthode de connexion n’est disponible actuellement. Veuillez contacter votre administrateur.',
        'sso_unavailable' => 'La connexion via ce fournisseur n’est pas disponible actuellement.',
        'sso_failed' => 'Échec de la connexion SSO',
    ],
    'entra' => [
        'access_denied' => 'La connexion a été annulée ou le consentement refusé.',
        'invalid_state' => 'La connexion a expiré ou n’est pas valide. Veuillez réessayer.',
        'multi_tenant_not_allowed' => 'Les points de terminaison multi-locataires (common, organizations, consumers) ne sont pas autorisés pour des raisons de sécurité. Veuillez indiquer l’ID ou le domaine de votre propre locataire.',
    ],
    'saml' => [
        'invalid_state' => 'La réponse SAML ne correspond à aucune connexion en cours dans ce navigateur ou a expiré. Veuillez relancer la connexion depuis la page de connexion.',
        'name_id_email_warning' => 'Avec le format « emailAddress », l’identifiant de l’utilisateur change lorsque l’adresse e-mail change, et les liaisons existantes sont perdues. Recommandé : persistent.',
        'metadata_refreshed' => 'Les métadonnées de l’IdP seront rechargées lors de la prochaine utilisation.',
    ],
    'logout' => [
        'completed' => 'Vous avez été déconnecté avec succès.',
        'idp_unconfirmed' => 'Vous avez été déconnecté. La déconnexion auprès du fournisseur d’identité n’a pas pu être confirmée.',
    ],
    'errors' => [
        'not_registered' => 'Aucun accès n’est configuré pour votre compte. Veuillez contacter votre administrateur.',
        'inactive' => 'Votre compte est désactivé.',
        'removed' => 'Votre compte a été supprimé. Veuillez contacter votre administrateur.',
        'domain_not_allowed' => 'Le domaine de votre adresse e-mail n’est pas autorisé pour cette connexion.',
        'no_role_match' => 'Votre compte n’est associé à aucun groupe ni rôle autorisé pour cette application. Veuillez contacter votre administrateur.',
        'last_provider' => 'Ce fournisseur d’identité est le dernier actif en mode SSO et ne peut être ni désactivé ni supprimé. Changez d’abord le mode de connexion.',
        'sso_requires_verified_provider' => 'Le mode SSO nécessite au moins un fournisseur d’identité actif dont le test de connexion a réussi.',
        'forced_local' => 'AUTH_FORCE_LOCAL est actif : la connexion locale s’applique quel que soit ce paramètre.',
    ],
    'test' => [
        'success' => 'Connexion réussie',
        'failure' => 'Échec du test de connexion',
        'http_error' => 'Le point de terminaison a répondu avec HTTP :status.',
        'unreachable' => 'Le point de terminaison est inaccessible : :message',
        'entra_ok' => 'Configuration OpenID chargée et identifiants client valides (émetteur : :issuer).',
        'entra_discovery_ok' => 'Configuration OpenID chargée (émetteur : :issuer). Secret client manquant – identifiants non vérifiés.',
        'entra_credentials_invalid' => 'Configuration OpenID chargée, mais l’ID client ou le secret n’est pas valide : :message',
        'saml_metadata_ok' => 'Métadonnées de l’IdP valides (entity ID : :entity, certificat valable jusqu’au :until).',
        'saml_manual_ok' => 'Certificat de l’IdP valable jusqu’au :until.',
        'saml_invalid_metadata' => 'Les métadonnées de l’IdP ne sont pas valides : :message',
        'saml_missing' => 'Ni l’URL des métadonnées ni l’entity ID, l’URL SSO et le certificat ne sont entièrement renseignés.',
        'saml_cert_invalid' => 'Le certificat de l’IdP n’est pas valide ou illisible.',
        'saml_cert_expired' => 'Le certificat de l’IdP a expiré le :date.',
        'saml_sp_key_mismatch' => 'Le certificat SP et la clé privée ne correspondent pas.',
    ],
    'settings' => [
        'title' => 'Authentification',
        'mode' => 'Mode de connexion',
        'options' => 'Options',
        'break_glass' => 'Connexion d’urgence pour les super-administrateurs',
        'break_glass_help' => 'Permet aux super-administrateurs de se connecter par mot de passe en mode SSO via :url',
        'default_role' => 'Rôle par défaut des nouveaux utilisateurs',
        'default_role_help' => 'Présélectionné lors de la création d’utilisateurs ; utilisé pour la création automatique via SSO si le fournisseur d’identité n’a pas de rôle par défaut propre.',
        'no_default_role' => 'Aucun',
        'password_reset' => 'Proposer « Mot de passe oublié »',
        'password_reset_help' => 'Nécessite un envoi d’e-mails configuré.',
    ],
    'methods' => [
        'password' => 'Mot de passe',
        'local' => 'Local',
        'sso' => 'SSO : :name',
        'idp' => 'par l’IdP : :name',
    ],
];
