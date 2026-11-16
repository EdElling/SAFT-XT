<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class PluginState
 *
 * @property int $id
 * @property string $plugin_id
 * @property bool $is_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PluginState extends Model
{
    protected $table = 'plugin_states';

    protected $casts = [
        'is_enabled' => 'bool',
    ];

    protected $fillable = [
        'plugin_id',
        'is_enabled',
    ];
}
