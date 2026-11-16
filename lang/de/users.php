<?php

declare(strict_types=1);

return [
    'model' => 'Benutzer',
    'plural' => 'Benutzer',
    'sso' => [
        'title' => 'SSO-Verknüpfungen',
        'provider' => 'Identity Provider',
        'provider_email' => 'E-Mail beim IdP',
        'provider_user_id' => 'IdP-Benutzer-ID',
        'linked_since' => 'Verknüpft seit',
        'unlink' => 'Trennen',
        'unlink_heading' => 'SSO-Verknüpfung trennen',
        'unlink_description' => 'Der Benutzer wird bei der nächsten SSO-Anmeldung erneut zugeordnet (sofern per E-Mail-Verknüpfung oder Auto-Provisionierung möglich).',
    ],
    'form' => [
        'master_data' => 'Stammdaten',
        'password' => 'Passwort',
        'password_keep' => 'Leer lassen, um das Passwort nicht zu ändern.',
        'password_sso' => 'Optional: Im SSO-Modus wird das Passwort nur für die Notfall-Anmeldung benötigt.',
        'password_confirmation' => 'Passwort bestätigen',
        'inactive_help' => 'Inaktive Benutzer können sich nicht anmelden.',
        'super_admin_only' => 'Nur Super-Admins dürfen die Rolle „super-admin“ vergeben.',
    ],
    'table' => [
        'last_login' => 'Letzter Login',
        'without_role' => 'Ohne Rolle',
        'sso_linked' => 'SSO-Verknüpfung',
    ],
    'delete' => [
        'soft' => 'Das Konto wird in den Papierkorb verschoben. Die Person kann sich nicht mehr anmelden, auch nicht per Single Sign-On, und erhält dabei auch kein neues Konto. Das Konto lässt sich wiederherstellen; sein Name bleibt im Änderungsprotokoll sichtbar.',
        'force' => 'Das Konto wird unwiderruflich entfernt, ebenso seine SSO-Verknüpfungen. Meldet sich die Person später per Single Sign-On an, kann ein neues Konto entstehen (Auto-Provisionierung oder E-Mail-Verknüpfung). Im Änderungsprotokoll erscheint statt des Namens nur noch die Benutzer-Nummer.',
    ],
];
