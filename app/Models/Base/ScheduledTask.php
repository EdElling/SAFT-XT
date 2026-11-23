<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ScheduledTask
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $app_id
 * @property string|null $name
 * @property string $type
 * @property array|null $parameters
 * @property string $frequency
 * @property int|null $interval_hours
 * @property string $at_time
 * @property int|null $weekday
 * @property bool $is_active
 * @property bool $is_system
 * @property Carbon|null $next_run_at
 * @property Carbon|null $last_run_at
 * @property string|null $last_status
 * @property int $consecutive_failures
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ScheduledTask extends Model
{
    protected $table = 'scheduled_tasks';

    protected $casts = [
        'app_id' => 'int',
        'parameters' => 'json',
        'interval_hours' => 'int',
        'weekday' => 'int',
        'is_active' => 'bool',
        'is_system' => 'bool',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'consecutive_failures' => 'int',
        'created_by' => 'int',
    ];

    protected $fillable = [
        'uuid',
        'app_id',
        'name',
        'type',
        'parameters',
        'frequency',
        'interval_hours',
        'at_time',
        'weekday',
        'is_active',
        'is_system',
        'next_run_at',
        'last_run_at',
        'last_status',
        'consecutive_failures',
        'created_by',
    ];
}
