<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class DataSnapshot
 *
 * @property int $id
 * @property int $custom_table_id
 * @property string $status
 * @property int $row_count
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $created_by
 * @property int|null $work_item_id
 * @property string|null $message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DataSnapshot extends Model
{
    protected $table = 'data_snapshots';

    protected $casts = [
        'custom_table_id' => 'int',
        'row_count' => 'int',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_by' => 'int',
        'work_item_id' => 'int',
    ];

    protected $fillable = [
        'custom_table_id',
        'status',
        'row_count',
        'started_at',
        'finished_at',
        'created_by',
        'work_item_id',
        'message',
    ];
}
