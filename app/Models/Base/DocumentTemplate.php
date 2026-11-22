<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class DocumentTemplate
 *
 * @property int $id
 * @property string $uuid
 * @property int $app_id
 * @property int $custom_table_id
 * @property string $name
 * @property string|null $recipient
 * @property string $body
 * @property string|null $file_name
 * @property string|null $language_field
 * @property string|null $file_field
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentTemplate extends Model
{
    protected $table = 'document_templates';

    protected $casts = [
        'app_id' => 'int',
        'custom_table_id' => 'int',
        'is_active' => 'bool',
        'sort_order' => 'int',
    ];

    protected $fillable = [
        'uuid',
        'app_id',
        'custom_table_id',
        'name',
        'recipient',
        'body',
        'file_name',
        'language_field',
        'file_field',
        'is_active',
        'sort_order',
    ];
}
