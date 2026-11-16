<?php

declare(strict_types=1);

return [
    'modes' => [
        'local' => [
            'label' => 'Locale',
            'description' => 'Accesso solo con e-mail e password.',
        ],
        'sso' => [
            'label' => 'Single sign-on',
            'description' => 'Accesso solo tramite un provider di identità.',
        ],
        'hybrid' => [
            'label' => 'Ibrido',
            'description' => 'Accesso con password o single sign-on.',
        ],
    ],
    'types' => [
        'entra' => 'Microsoft Entra ID',
        'saml' => 'SAML 2.0',
    ],
    'login' => [
        'sso_with' => 'Accedi con :name',
        'sso_divider' => 'oppure',
        'break_glass' => 'Accesso di emergenza per gli amministratori',
        'break_glass_hint' => 'Solo per i super amministratori, se l’accesso SSO non funziona.',
        'no_method' => 'Al momento non è disponibile alcun metodo di accesso. Contatta l’amministratore.',
        'sso_unavailable' => 'L’accesso tramite questo provider non è al momento disponibile.',
        'sso_failed' => 'Accesso SSO non riuscito',
    ],
    'entra' => [
        'access_denied' => 'L’accesso è stato annullato o il consenso è stato negato.',
        'invalid_state' => 'L’accesso è scaduto o non è valido. Riprova.',
        'multi_tenant_not_allowed' => 'Gli endpoint multi-tenant (common, organizations, consumers) non sono consentiti per motivi di sicurezza. Indica l’ID o il dominio del tuo tenant.',
    ],
    'saml' => [
        'invalid_state' => 'La risposta SAML non corrisponde ad alcun accesso in corso in questo browser oppure è scaduta. Riavvia l’accesso dalla pagina di login.',
        'name_id_email_warning' => 'Con il formato «emailAddress» l’ID utente cambia quando cambia l’indirizzo e-mail e i collegamenti esistenti vanno persi. Consigliato: persistent.',
        'metadata_refreshed' => 'I metadati dell’IdP verranno ricaricati al prossimo utilizzo.',
    ],
    'logout' => [
        'completed' => 'Disconnessione avvenuta con successo.',
        'idp_unconfirmed' => 'Sei stato disconnesso. La disconnessione presso il provider di identità non ha potuto essere confermata.',
    ],
    'errors' => [
        'not_registered' => 'Per il tuo account non è configurato alcun accesso. Contatta l’amministratore.',
        'inactive' => 'Il tuo account è disattivato.',
        'removed' => 'Il tuo account è stato rimosso. Contatta l’amministratore.',
        'domain_not_allowed' => 'Il dominio del tuo indirizzo e-mail non è consentito per questo accesso.',
        'no_role_match' => 'Il tuo account non è associato ad alcun gruppo o ruolo autorizzato per questa applicazione. Contatta l’amministratore.',
        'last_provider' => 'Questo provider di identità è l’ultimo attivo in modalità SSO e non può essere disattivato né eliminato. Cambia prima la modalità di accesso.',
        'sso_requires_verified_provider' => 'La modalità SSO richiede almeno un provider di identità attivo con un test di connessione riuscito.',
        'forced_local' => 'AUTH_FORCE_LOCAL è attivo: si applica l’accesso locale indipendentemente da questa impostazione.',
    ],
    'test' => [
        'success' => 'Connessione riuscita',
        'failure' => 'Test di connessione non riuscito',
        'http_error' => 'L’endpoint ha risposto con HTTP :status.',
        'unreachable' => 'L’endpoint non è raggiungibile: :message',
        'entra_ok' => 'Configurazione OpenID caricata e credenziali client valide (emittente: :issuer).',
        'entra_discovery_ok' => 'Configurazione OpenID caricata (emittente: :issuer). Client secret mancante – credenziali non verificate.',
        'entra_credentials_invalid' => 'Configurazione OpenID caricata, ma l’ID client o il secret non è valido: :message',
        'saml_metadata_ok' => 'Metadati dell’IdP validi (entity ID: :entity, certificato valido fino al :until).',
        'saml_manual_ok' => 'Certificato dell’IdP valido fino al :until.',
        'saml_invalid_metadata' => 'I metadati dell’IdP non sono validi: :message',
        'saml_missing' => 'Non sono stati indicati completamente né l’URL dei metadati né entity ID, URL SSO e certificato.',
        'saml_cert_invalid' => 'Il certificato dell’IdP non è valido o non è leggibile.',
        'saml_cert_expired' => 'Il certificato dell’IdP è scaduto il :date.',
        'saml_sp_key_mismatch' => 'Il certificato SP e la chiave privata non corrispondono.',
    ],
    'settings' => [
        'title' => 'Autenticazione',
        'mode' => 'Modalità di accesso',
        'options' => 'Opzioni',
        'break_glass' => 'Accesso di emergenza per i super amministratori',
        'break_glass_help' => 'Consente ai super amministratori di accedere con password in modalità SSO tramite :url',
        'default_role' => 'Ruolo predefinito per i nuovi utenti',
        'default_role_help' => 'Preselezionato alla creazione degli utenti; usato per la creazione automatica tramite SSO se il provider di identità non ha un proprio ruolo predefinito.',
        'no_default_role' => 'Nessuno',
        'password_reset' => 'Offri «Password dimenticata»',
        'password_reset_help' => 'Richiede un invio e-mail configurato.',
    ],
    'methods' => [
        'password' => 'Password',
        'local' => 'Locale',
        'sso' => 'SSO: :name',
        'idp' => 'tramite IdP: :name',
    ],
];
