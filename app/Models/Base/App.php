<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class App
 *
 * @property int $id
 * @property string $uuid
 * @property array $name
 * @property array|null $description
 * @property string|null $icon
 * @property string|null $prefix
 * @property string|null $version
 * @property int $interface_version
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $removal_due_at
 * @property int|null $removal_requested_by
 * @property string|null $removal_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class App extends Model
{
    protected $table = 'apps';

    protected $casts = [
        'sort_order' => 'int',
        'interface_version' => 'int',
        'is_active' => 'bool',
        'removal_due_at' => 'datetime',
        'removal_requested_by' => 'int',
    ];

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'icon',
        'prefix',
        'version',
        'interface_version',
        'sort_order',
        'is_active',
        'removal_due_at',
        'removal_requested_by',
        'removal_error',
    ];
}
