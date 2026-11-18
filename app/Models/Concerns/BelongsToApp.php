<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\App;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Zuordnung zu einer App (B-107); leer bei Systemobjekten. */
trait BelongsToApp
{
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInApp(Builder $query, App|int|null $app): Builder
    {
        $id = $app instanceof App ? $app->id : $app;

        return $id === null ? $query->whereNull($this->qualifyColumn('app_id')) : $query->where($this->qualifyColumn('app_id'), $id);
    }
}
