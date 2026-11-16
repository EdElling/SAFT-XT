<?php

declare(strict_types=1);

namespace App\DynamicData\Eloquent;

use App\Models\DynamicRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * MorphTo, das Typen "dynamic:<tabelle>" auf DynamicRecord abbildet (Eager Loading).
 * Gelöschte Designer-Tabellen ergeben "kein Subject" statt eines Fehlers.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphTo<TRelatedModel, TDeclaringModel>
 */
class DynamicAwareMorphTo extends MorphTo
{
    public function createModelByType($type)
    {
        if (DynamicRecord::isMorphType($type)) {
            return DynamicRecord::forMorphType($type) ?? new DynamicRecord;
        }

        return parent::createModelByType($type);
    }

    protected function getResultsByType($type)
    {
        if (DynamicRecord::isMorphType($type) && DynamicRecord::forMorphType($type) === null) {
            return new Collection;
        }

        return parent::getResultsByType($type);
    }
}
