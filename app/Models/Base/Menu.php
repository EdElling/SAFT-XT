<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class Menu
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $label übersetzbar, gespeichert als JSON je Sprache
 * @property string|null $route
 * @property string|null $url
 * @property string|null $icon
 * @property string|null $permission_name
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array|null $route_parameters
 * @property array|null $view_config
 * @property \App\Models\Menu|null $menu
 * @property Collection|\App\Models\Menu[] $menus
 */
class Menu extends Model
{
    protected $table = 'menus';

    protected $casts = [
        'parent_id' => 'int',
        'sort_order' => 'int',
        'is_active' => 'bool',
        'route_parameters' => 'array',
        'view_config' => 'array',
    ];

    protected $fillable = [
        'parent_id',
        'label',
        'route',
        'url',
        'icon',
        'permission_name',
        'sort_order',
        'is_active',
        'route_parameters',
        'view_config',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Menu::class, 'parent_id');
    }

    public function menus(): HasMany
    {
        return $this->hasMany(\App\Models\Menu::class, 'parent_id');
    }
}
