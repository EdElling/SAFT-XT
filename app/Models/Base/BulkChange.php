<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\App;
use App\Models\CustomTable;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class BulkChange
 *
 * @property int $id
 * @property string $run
 * @property int|null $app_id
 * @property int $custom_table_id
 * @property int|null $user_id
 * @property string $operation
 * @property array|null $field_changes
 * @property array $record_ids
 * @property int $count
 * @property array|null $result
 * @property int|null $undo_of_id
 * @property int|null $undone_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property App|null $app
 * @property CustomTable $custom_table
 * @property \App\Models\BulkChange|null $bulk_change
 * @property User|null $user
 * @property Collection|\App\Models\BulkChange[] $bulk_changes
 */
class BulkChange extends Model
{
    protected $table = 'bulk_changes';

    protected $casts = [
        'app_id' => 'int',
        'custom_table_id' => 'int',
        'user_id' => 'int',
        'field_changes' => 'json',
        'record_ids' => 'json',
        'count' => 'int',
        'result' => 'json',
        'undo_of_id' => 'int',
        'undone_by_id' => 'int',
    ];

    protected $fillable = [
        'run',
        'app_id',
        'custom_table_id',
        'user_id',
        'operation',
        'field_changes',
        'record_ids',
        'count',
        'result',
        'undo_of_id',
        'undone_by_id',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class);
    }

    public function bulk_change(): BelongsTo
    {
        return $this->belongsTo(\App\Models\BulkChange::class, 'undone_by_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bulk_changes(): HasMany
    {
        return $this->hasMany(\App\Models\BulkChange::class, 'undone_by_id');
    }
}
