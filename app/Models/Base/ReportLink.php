<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ReportLink
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $app_id
 * @property string $label
 * @property int $source_table_id
 * @property int $source_field_id
 * @property int $target_table_id
 * @property int|null $target_field_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReportLink extends Model
{
    protected $table = 'report_links';

    protected $casts = [
        'app_id' => 'int',
        'source_table_id' => 'int',
        'source_field_id' => 'int',
        'target_table_id' => 'int',
        'target_field_id' => 'int',
    ];

    protected $fillable = [
        'uuid',
        'app_id',
        'label',
        'source_table_id',
        'source_field_id',
        'target_table_id',
        'target_field_id',
    ];
}
