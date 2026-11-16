<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class User
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $locale
 * @property int|null $ai_daily_limit
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property string|null $deleted_at
 * @property bool|null $high_contrast
 * @property array|null $dashboard_layout
 * @property string|null $theme
 * @property string|null $number_format
 * @property Collection|SocialAccount[] $social_accounts
 */
class User extends \Illuminate\Foundation\Auth\User
{
    use SoftDeletes;

    protected $table = 'users';

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'bool',
        'ai_daily_limit' => 'int',
        'last_login_at' => 'datetime',
        'high_contrast' => 'bool',
        'dashboard_layout' => 'array',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'is_active',
        'last_login_at',
        'locale',
        'ai_daily_limit',
        'high_contrast',
        'number_format',
    ];

    public function social_accounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }
}
