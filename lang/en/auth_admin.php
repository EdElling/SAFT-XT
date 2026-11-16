<?php

declare(strict_types=1);

return [
    'modes' => [
        'local' => [
            'label' => 'Local',
            'description' => 'Sign in with email and password only.',
        ],
        'sso' => [
            'label' => 'Single sign-on',
            'description' => 'Sign in through an identity provider only.',
        ],
        'hybrid' => [
            'label' => 'Hybrid',
            'description' => 'Sign in with password or single sign-on.',
        ],
    ],
    'types' => [
        'entra' => 'Microsoft Entra ID',
        'saml' => 'SAML 2.0',
    ],
    'login' => [
        'sso_with' => 'Sign in with :name',
        'sso_divider' => 'or',
        'break_glass' => 'Emergency sign-in for administrators',
        'break_glass_hint' => 'Super admins only, if single sign-on is not working.',
        'no_method' => 'No sign-in method is currently available. Please contact your administrator.',
        'sso_unavailable' => 'Signing in with this provider is currently unavailable.',
        'sso_failed' => 'Single sign-on failed',
    ],
    'entra' => [
        'access_denied' => 'Sign-in was cancelled or consent was denied.',
        'invalid_state' => 'The sign-in has expired or is invalid. Please try again.',
        'multi_tenant_not_allowed' => 'Multi-tenant endpoints (common, organizations, consumers) are not allowed for security reasons. Please enter the tenant ID or domain of your own tenant.',
    ],
    'saml' => [
        'invalid_state' => 'The SAML response does not match a pending sign-in in this browser or has expired. Please start the sign-in again from the login page.',
        'name_id_email_warning' => 'With the “emailAddress” format, the user ID changes when the email address changes, and existing links are lost. Recommended: persistent.',
        'metadata_refreshed' => 'IdP metadata will be reloaded on next use.',
    ],
    'logout' => [
        'completed' => 'You have been signed out successfully.',
        'idp_unconfirmed' => 'You have been signed out. Sign-out at the identity provider could not be confirmed.',
    ],
    'errors' => [
        'not_registered' => 'No access has been set up for your account. Please contact your administrator.',
        'inactive' => 'Your account is deactivated.',
        'removed' => 'Your account has been removed. Please contact your administrator.',
        'domain_not_allowed' => 'Your email domain is not allowed for this sign-in.',
        'no_role_match' => 'Your account is not assigned to any group or role permitted for this application. Please contact your administrator.',
        'last_provider' => 'This identity provider is the last active one in SSO mode and cannot be disabled or deleted. Change the sign-in mode first.',
        'sso_requires_verified_provider' => 'Single sign-on mode requires at least one active identity provider with a successful connection test.',
        'forced_local' => 'AUTH_FORCE_LOCAL is active: local sign-in applies regardless of this setting.',
    ],
    'test' => [
        'success' => 'Connection successful',
        'failure' => 'Connection test failed',
        'http_error' => 'The endpoint responded with HTTP :status.',
        'unreachable' => 'The endpoint is unreachable: :message',
        'entra_ok' => 'OpenID configuration loaded and client credentials valid (issuer: :issuer).',
        'entra_discovery_ok' => 'OpenID configuration loaded (issuer: :issuer). Client secret missing – credentials not verified.',
        'entra_credentials_invalid' => 'OpenID configuration loaded, but client ID or secret is invalid: :message',
        'saml_metadata_ok' => 'IdP metadata valid (entity ID: :entity, certificate valid until :until).',
        'saml_manual_ok' => 'IdP certificate valid until :until.',
        'saml_invalid_metadata' => 'The IdP metadata is invalid: :message',
        'saml_missing' => 'Neither a metadata URL nor entity ID, SSO URL and certificate are fully specified.',
        'saml_cert_invalid' => 'The IdP certificate is invalid or unreadable.',
        'saml_cert_expired' => 'The IdP certificate expired on :date.',
        'saml_sp_key_mismatch' => 'SP certificate and private key do not match.',
    ],
    'settings' => [
        'title' => 'Authentication',
        'mode' => 'Sign-in mode',
        'options' => 'Options',
        'break_glass' => 'Emergency sign-in for super admins',
        'break_glass_help' => 'Allows super admins to sign in with a password in SSO mode via :url',
        'default_role' => 'Default role for new users',
        'default_role_help' => 'Preselected when creating users; used for SSO auto-provisioning when the identity provider has no default role of its own.',
        'no_default_role' => 'None',
        'password_reset' => 'Offer “Forgot password”',
        'password_reset_help' => 'Requires a configured mail transport.',
    ],
    'methods' => [
        'password' => 'Password',
        'local' => 'Local',
        'sso' => 'SSO: :name',
        'idp' => 'by IdP: :name',
    ],
];
