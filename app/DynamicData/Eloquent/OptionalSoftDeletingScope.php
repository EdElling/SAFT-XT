<?php

declare(strict_types=1);

namespace App\DynamicData\Eloquent;

use App\Models\DynamicRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Wie Laravels SoftDeletingScope, aber nur für Tabellen mit deleted_at: Externe Tabellen haben
 * keine solche Spalte. withTrashed() & Co. bleiben verfügbar und sind dort wirkungslos.
 *
 * Registriert unter dem Schlüssel SoftDeletingScope::class, denn Filament und Laravel entfernen
 * den Scope über diesen Namen (z. B. beim Wiederherstellen).
 */
final class OptionalSoftDeletingScope extends SoftDeletingScope
{
    public function apply(Builder $builder, Model $model)
    {
        if (! self::applies($model)) {
            return;
        }

        parent::apply($builder, $model);
    }

    private static function applies(Model $model): bool
    {
        return ! $model instanceof DynamicRecord || $model->hasSoftDeleteColumn();
    }

    protected function addWithTrashed(Builder $builder)
    {
        $builder->macro('withTrashed', function (Builder $builder, $withTrashed = true) {
            return $withTrashed ? $builder->withoutGlobalScope(SoftDeletingScope::class) : $builder->withoutTrashed();
        });
    }

    protected function addWithoutTrashed(Builder $builder)
    {
        $builder->macro('withoutTrashed', function (Builder $builder) {
            $model = $builder->getModel();
            $builder->withoutGlobalScope(SoftDeletingScope::class);

            return self::applies($model) ? $builder->whereNull($builder->qualifyColumn($model->getQualifiedDeletedAtColumn())) : $builder;
        });
    }

    protected function addOnlyTrashed(Builder $builder)
    {
        $builder->macro('onlyTrashed', function (Builder $builder) {
            $model = $builder->getModel();
            $builder->withoutGlobalScope(SoftDeletingScope::class);

            // Ohne deleted_at gibt es keine gelöschten Datensätze.
            return self::applies($model)
                ? $builder->whereNotNull($builder->qualifyColumn($model->getQualifiedDeletedAtColumn()))
                : $builder->whereRaw('1 = 0');
        });
    }
}
