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
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class App extends Model
{
    protected $table = 'apps';

    protected $casts = [
        'sort_order' => 'int',
        'is_active' => 'bool',
    ];

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'icon',
        'prefix',
        'sort_order',
        'is_active',
    ];
}
