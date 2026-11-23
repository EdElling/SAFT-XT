<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\CustomTable;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class RecordFile
 *
 * @property string $id
 * @property int $custom_table_id
 * @property string $field
 * @property string|null $record_id
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property int|null $uploaded_by
 * @property int|null $document_template_id
 * @property string|null $sha256
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property CustomTable $custom_table
 * @property User|null $user
 */
class RecordFile extends Model
{
    protected $table = 'record_files';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'custom_table_id' => 'int',
        'size' => 'int',
        'width' => 'int',
        'height' => 'int',
        'uploaded_by' => 'int',
        'document_template_id' => 'int',
    ];

    protected $fillable = [
        'id',
        'custom_table_id',
        'field',
        'record_id',
        'disk',
        'path',
        'name',
        'mime',
        'size',
        'width',
        'height',
        'uploaded_by',
        'document_template_id',
        'sha256',
    ];

    public function custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
