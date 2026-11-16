<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\Role;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class IdentityProvider
 *
 * @property int $id
 * @property string $type
 * @property string $name
 * @property string $slug
 * @property bool $is_enabled
 * @property int $sort_order
 * @property string|null $settings
 * @property string|null $secrets
 * @property bool $link_by_email
 * @property bool $auto_provision
 * @property int|null $default_role_id
 * @property string|null $allowed_domains
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_successful
 * @property string|null $last_test_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Role|null $role
 * @property Collection|SocialAccount[] $social_accounts
 * @property Collection|Role[] $roles
 */
class IdentityProvider extends Model
{
    protected $table = 'identity_providers';

    protected $casts = [
        'is_enabled' => 'bool',
        'sort_order' => 'int',
        'link_by_email' => 'bool',
        'auto_provision' => 'bool',
        'default_role_id' => 'int',
        'last_tested_at' => 'datetime',
        'last_test_successful' => 'bool',
    ];

    protected $hidden = [
        'secrets',
    ];

    protected $fillable = [
        'type',
        'name',
        'slug',
        'is_enabled',
        'sort_order',
        'settings',
        'secrets',
        'link_by_email',
        'auto_provision',
        'default_role_id',
        'allowed_domains',
        'last_tested_at',
        'last_test_successful',
        'last_test_message',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'default_role_id');
    }

    public function social_accounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'identity_provider_role_mappings')
            ->withPivot('id', 'external_value')
            ->withTimestamps();
    }
}
