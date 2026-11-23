<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\IdentityProvider;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class IdentityProviderRoleMapping
 *
 * @property int $id
 * @property int $identity_provider_id
 * @property string $external_value
 * @property int $role_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property IdentityProvider $identity_provider
 * @property Role $role
 */
class IdentityProviderRoleMapping extends Model
{
    protected $table = 'identity_provider_role_mappings';

    protected $casts = [
        'identity_provider_id' => 'int',
        'role_id' => 'int',
    ];

    protected $fillable = [
        'identity_provider_id',
        'external_value',
        'role_id',
    ];

    public function identity_provider(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
