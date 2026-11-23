<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class UserTableColumn
 *
 * @property int $id
 * @property int $user_id
 * @property string $table_key
 * @property array $columns
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User $user
 */
class UserTableColumn extends Model
{
    protected $table = 'user_table_columns';

    protected $casts = [
        'user_id' => 'int',
        'columns' => 'json',
    ];

    protected $fillable = [
        'user_id',
        'table_key',
        'columns',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
