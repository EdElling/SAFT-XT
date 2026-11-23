<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\CustomTable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class CustomField
 *
 * @property int $id
 * @property string $uuid
 * @property int $custom_table_id
 * @property string $name
 * @property string $label
 * @property string $type
 * @property string|null $external_type
 * @property bool $is_read_only
 * @property bool $is_generated
 * @property array|null $options
 * @property array|null $validation_rules
 * @property bool $is_required
 * @property bool $is_unique
 * @property bool $is_searchable
 * @property bool $is_sortable
 * @property bool $show_in_table
 * @property bool $show_in_form
 * @property bool $is_active
 * @property bool $is_shared
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property CustomTable $custom_table
 */
class CustomField extends Model
{
    protected $table = 'custom_fields';

    protected $casts = [
        'custom_table_id' => 'int',
        'options' => 'array',
        'validation_rules' => 'array',
        'is_required' => 'bool',
        'is_unique' => 'bool',
        'is_searchable' => 'bool',
        'is_sortable' => 'bool',
        'show_in_table' => 'bool',
        'show_in_form' => 'bool',
        'is_active' => 'bool',
        'is_shared' => 'bool',
        'is_read_only' => 'bool',
        'is_generated' => 'bool',
        'sort_order' => 'int',
    ];

    protected $fillable = [
        'uuid',
        'custom_table_id',
        'name',
        'label',
        'type',
        'external_type',
        'options',
        'validation_rules',
        'is_required',
        'is_unique',
        'is_searchable',
        'is_sortable',
        'show_in_table',
        'show_in_form',
        'is_active',
        'is_shared',
        'is_read_only',
        'is_generated',
        'sort_order',
    ];

    public function custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class);
    }
}
