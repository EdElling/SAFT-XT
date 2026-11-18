<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Report
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $app_id
 * @property string $name
 * @property string|null $description
 * @property int|null $owner_id
 * @property array $definition
 * @property bool $is_shared
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Report extends Model
{
    protected $table = 'reports';

    protected $casts = [
        'app_id' => 'int',
        'owner_id' => 'int',
        'definition' => 'array',
        'is_shared' => 'bool',
    ];

    protected $fillable = [
        'uuid',
        'app_id',
        'name',
        'description',
        'owner_id',
        'definition',
        'is_shared',
    ];
}
