<?php

declare(strict_types=1);

return [
    'model' => 'utente',
    'plural' => 'utenti',
    'sso' => [
        'title' => 'Collegamenti SSO',
        'provider' => 'Provider di identità',
        'provider_email' => 'E-mail presso l’IdP',
        'provider_user_id' => 'ID utente IdP',
        'linked_since' => 'Collegato dal',
        'unlink' => 'Scollega',
        'unlink_heading' => 'Scollega account SSO',
        'unlink_description' => 'L’utente verrà nuovamente associato al prossimo accesso SSO (se il collegamento tramite e-mail o la creazione automatica lo consentono).',
    ],
    'form' => [
        'master_data' => 'Dati principali',
        'password' => 'Password',
        'password_keep' => 'Lasciare vuoto per mantenere la password attuale.',
        'password_sso' => 'Facoltativo: in modalità SSO la password serve solo per l’accesso di emergenza.',
        'password_confirmation' => 'Conferma password',
        'inactive_help' => 'Gli utenti inattivi non possono accedere.',
        'super_admin_only' => 'Solo i super amministratori possono assegnare il ruolo «super-admin».',
    ],
    'table' => [
        'last_login' => 'Ultimo accesso',
        'without_role' => 'Senza ruolo',
        'sso_linked' => 'Collegamento SSO',
    ],
    'delete' => [
        'soft' => 'L’account viene spostato nel cestino. La persona non può più accedere, nemmeno con il single sign-on, e in questo modo non ottiene un nuovo account. L’account può essere ripristinato; il suo nome resta visibile nel registro delle modifiche.',
        'force' => 'L’account viene rimosso definitivamente, insieme ai suoi collegamenti SSO. Se in seguito la persona accede con il single sign-on, può essere creato un nuovo account (provisioning automatico o collegamento tramite e-mail). Il registro delle modifiche mostra quindi solo il numero dell’utente invece del nome.',
    ],
];
