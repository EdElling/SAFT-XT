<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class UserTableState
 *
 * @property int $id
 * @property int $user_id
 * @property string $table_key
 * @property array $state
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User $user
 */
class UserTableState extends Model
{
    protected $table = 'user_table_states';

    protected $casts = [
        'user_id' => 'int',
        'state' => 'json',
    ];

    protected $fillable = [
        'user_id',
        'table_key',
        'state',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
