<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/** Filter und Spalte „App“ für die Listen der Administration (B-107). */
final class AppFilter
{
    private const string SYSTEM = 'system';

    /** @param  bool  $system  zusätzlich „System“ (ohne App) anbieten */
    public static function make(bool $system = false): SelectFilter
    {
        return SelectFilter::make('app_id')
            ->label(__('apps.app'))
            ->options(static fn (): array => ($system ? [self::SYSTEM => __('apps.system')] : []) + AppSelect::options())
            ->query(static function (Builder $query, array $data): Builder {
                $value = $data['value'] ?? null;

                return match (true) {
                    blank($value) => $query,
                    $value === self::SYSTEM => $query->whereNull($query->qualifyColumn('app_id')),
                    default => $query->where($query->qualifyColumn('app_id'), (int) $value),
                };
            });
    }

    public static function column(): TextColumn
    {
        return TextColumn::make('app.name')
            ->label(__('apps.app'))
            ->placeholder(__('apps.system'))
            ->toggleable();
    }
}
