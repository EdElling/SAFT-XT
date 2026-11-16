<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\IdentityProvider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class SocialAccount
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string|null $provider_email
 * @property string|null $token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $identity_provider_id
 * @property User $user
 * @property IdentityProvider|null $identity_provider
 */
class SocialAccount extends Model
{
    protected $table = 'social_accounts';

    protected $casts = [
        'user_id' => 'int',
        'token_expires_at' => 'datetime',
        'identity_provider_id' => 'int',
    ];

    protected $hidden = [
        'token',
        'refresh_token',
    ];

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
        'token',
        'refresh_token',
        'token_expires_at',
        'identity_provider_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function identity_provider(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class);
    }
}
