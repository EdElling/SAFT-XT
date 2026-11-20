<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\CustomField;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class CustomTable
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $app_id
 * @property string $name
 * @property string $source
 * @property int|null $database_connection_id
 * @property string|null $external_schema
 * @property string|null $external_table
 * @property string|null $external_kind
 * @property array|null $key_columns
 * @property string $label
 * @property string|null $description
 * @property string|null $icon
 * @property bool $is_active
 * @property bool $is_shared
 * @property bool $export_enabled
 * @property bool $import_enabled
 * @property bool $allow_create
 * @property bool $allow_update
 * @property bool $allow_delete
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array|null $detail_field_ids
 * @property array|null $validation_rules
 * @property array|null $check_constraints
 * @property int $snapshot_keep
 * @property int|null $snapshot_max_days
 * @property Collection|CustomField[] $custom_fields
 */
class CustomTable extends Model
{
    protected $table = 'custom_tables';

    protected $casts = [
        'app_id' => 'int',
        'is_active' => 'bool',
        'is_shared' => 'bool',
        'export_enabled' => 'bool',
        'import_enabled' => 'bool',
        'allow_create' => 'bool',
        'allow_update' => 'bool',
        'allow_delete' => 'bool',
        'detail_field_ids' => 'array',
        'validation_rules' => 'array',
        'check_constraints' => 'array',
        'snapshot_keep' => 'int',
        'snapshot_max_days' => 'int',
        'database_connection_id' => 'int',
        'key_columns' => 'array',
    ];

    protected $fillable = [
        'uuid',
        'app_id',
        'name',
        'source',
        'database_connection_id',
        'external_schema',
        'external_table',
        'external_kind',
        'key_columns',
        'label',
        'description',
        'icon',
        'is_active',
        'is_shared',
        'export_enabled',
        'import_enabled',
        'allow_create',
        'allow_update',
        'allow_delete',
        'detail_field_ids',
        'validation_rules',
        'check_constraints',
        'snapshot_keep',
        'snapshot_max_days',
    ];

    public function custom_fields(): HasMany
    {
        return $this->hasMany(CustomField::class);
    }
}
