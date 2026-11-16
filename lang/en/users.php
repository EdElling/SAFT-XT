<?php

declare(strict_types=1);

return [
    'model' => 'user',
    'plural' => 'users',
    'sso' => [
        'title' => 'SSO links',
        'provider' => 'Identity provider',
        'provider_email' => 'Email at the IdP',
        'provider_user_id' => 'IdP user ID',
        'linked_since' => 'Linked since',
        'unlink' => 'Unlink',
        'unlink_heading' => 'Unlink SSO account',
        'unlink_description' => 'The user will be matched again at the next SSO sign-in (if linking by email or auto-provisioning allows it).',
    ],
    'form' => [
        'master_data' => 'Master data',
        'password' => 'Password',
        'password_keep' => 'Leave empty to keep the current password.',
        'password_sso' => 'Optional: in SSO mode the password is only needed for emergency sign-in.',
        'password_confirmation' => 'Confirm password',
        'inactive_help' => 'Inactive users cannot sign in.',
        'super_admin_only' => 'Only super admins may assign the “super-admin” role.',
    ],
    'table' => [
        'last_login' => 'Last sign-in',
        'without_role' => 'Without role',
        'sso_linked' => 'SSO link',
    ],
    'delete' => [
        'soft' => 'The account is moved to the trash. The person can no longer sign in, not even with single sign-on, and will not get a new account that way. The account can be restored; its name stays visible in the activity log.',
        'force' => 'The account is removed for good, together with its SSO links. If the person later signs in with single sign-on, a new account may be created (auto-provisioning or linking by email). The activity log then shows only the user number instead of the name.',
    ],
];
