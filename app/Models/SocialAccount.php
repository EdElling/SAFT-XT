<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\SocialAccount as BaseSocialAccount;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends BaseSocialAccount
{
    use HasAuditLog;

    /**
     * OAuth-Tokens werden verschlüsselt (APP_KEY) in der Datenbank abgelegt.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    public function identityProvider(): BelongsTo
    {
        return $this->identity_provider();
    }
}
