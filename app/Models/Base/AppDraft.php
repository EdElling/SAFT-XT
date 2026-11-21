<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class AppDraft
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property array|null $history
 * @property array|null $proposal
 * @property array|null $definition
 * @property array|null $reports
 * @property string $status
 * @property int|null $app_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AppDraft extends Model
{
    protected $table = 'app_drafts';

    protected $casts = [
        'user_id' => 'int',
        'history' => 'array',
        'proposal' => 'array',
        'definition' => 'array',
        'reports' => 'array',
        'app_id' => 'int',
    ];

    protected $fillable = [
        'user_id',
        'title',
        'history',
        'proposal',
        'definition',
        'reports',
        'status',
        'app_id',
    ];
}
