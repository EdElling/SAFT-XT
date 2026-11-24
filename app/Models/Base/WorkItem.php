<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\App;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class WorkItem
 *
 * @property int $id
 * @property string $uuid
 * @property string $type
 * @property int|null $app_id
 * @property int|null $user_id
 * @property array|null $simulated_roles
 * @property array|null $parameters
 * @property string $status
 * @property int|null $total
 * @property int $done
 * @property array|null $state
 * @property array|null $result
 * @property string|null $message
 * @property array|null $error_detail
 * @property int $stalls
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property App|null $app
 * @property User|null $user
 */
class WorkItem extends Model
{
    protected $table = 'work_items';

    protected $casts = [
        'app_id' => 'int',
        'user_id' => 'int',
        'simulated_roles' => 'json',
        'parameters' => 'json',
        'total' => 'int',
        'done' => 'int',
        'state' => 'json',
        'result' => 'json',
        'error_detail' => 'json',
        'stalls' => 'int',
        'cancel_requested_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $fillable = [
        'uuid',
        'type',
        'app_id',
        'user_id',
        'simulated_roles',
        'parameters',
        'status',
        'total',
        'done',
        'state',
        'result',
        'message',
        'error_detail',
        'stalls',
        'cancel_requested_at',
        'heartbeat_at',
        'started_at',
        'finished_at',
        'expires_at',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
