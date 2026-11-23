<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class TaskRun
 *
 * @property int $id
 * @property int $scheduled_task_id
 * @property string $trigger
 * @property int|null $triggered_by
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string|null $message
 * @property array|null $result
 * @property array|null $error_detail
 */
class TaskRun extends Model
{
    public $timestamps = false;

    protected $table = 'task_runs';

    protected $casts = [
        'scheduled_task_id' => 'int',
        'triggered_by' => 'int',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'result' => 'json',
        'error_detail' => 'json',
    ];

    protected $fillable = [
        'scheduled_task_id',
        'trigger',
        'triggered_by',
        'status',
        'started_at',
        'finished_at',
        'message',
        'result',
        'error_detail',
    ];
}
