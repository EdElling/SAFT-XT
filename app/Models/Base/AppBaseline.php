<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class AppBaseline
 *
 * @property int $id
 * @property int $app_id
 * @property string $kind
 * @property string|null $version
 * @property array $definition
 * @property int|null $installed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AppBaseline extends Model
{
    protected $table = 'app_baselines';

    protected $casts = [
        'app_id' => 'int',
        'definition' => 'array',
        'installed_by' => 'int',
    ];

    protected $fillable = [
        'app_id',
        'kind',
        'version',
        'definition',
        'installed_by',
    ];
}
