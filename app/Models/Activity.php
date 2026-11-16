<?php

declare(strict_types=1);

namespace App\Models;

use App\DynamicData\Eloquent\DynamicAwareMorphTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Activity-Model der Anwendung (config activitylog.activity_model): löst zusätzlich
 * Subjects aus Tabellen des Table-Designers ("dynamic:<tabelle>") auf.
 */
class Activity extends SpatieActivity
{
    protected function morphInstanceTo($target, $name, $type, $id, $ownerKey)
    {
        if (! DynamicRecord::isMorphType($target)) {
            return parent::morphInstanceTo($target, $name, $type, $id, $ownerKey);
        }

        $instance = DynamicRecord::forMorphType($target);

        // Tabelle gelöscht: Relation, die garantiert nichts liefert.
        $query = $instance?->newQuery() ?? $this->newQuery()->whereKey([]);

        return $this->newMorphTo($query, $this, $id, $ownerKey ?? 'id', $type, $name);
    }

    protected function newMorphTo(Builder $query, Model $parent, $foreignKey, $ownerKey, $type, $relation)
    {
        return new DynamicAwareMorphTo($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }
}
