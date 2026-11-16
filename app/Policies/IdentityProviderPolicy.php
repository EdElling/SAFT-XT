<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ChecksResourcePermissions;

class IdentityProviderPolicy
{
    use ChecksResourcePermissions;

    protected function permissionPrefix(): string
    {
        return 'identity_providers';
    }
}
