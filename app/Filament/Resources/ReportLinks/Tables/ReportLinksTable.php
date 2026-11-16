<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReportLinks\Tables;

use App\Models\ReportLink;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReportLinksTable
{
    public static function configure(Table $table): Table
    {
        $side = fn (ReportLink $link, bool $source): string => $source
            ? $link->sourceTable?->label.' › '.$link->sourceField?->label
            : $link->targetTable?->label.' › '.($link->targetField?->label ?? 'id');
        $origin = fn (ReportLink $link, bool $source): string => ($table = $source ? $link->sourceTable : $link->targetTable)?->isExternal()
            ? __('report_links.external', ['connection' => (string) $table->databaseConnection?->name])
            : __('report_links.designer');

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['sourceTable.databaseConnection', 'sourceField', 'targetTable.databaseConnection', 'targetField']))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('label')->label(__('report_links.label'))->searchable()->wrap(),
                TextColumn::make('source')
                    ->label(__('report_links.source'))
                    ->state(fn (ReportLink $record): string => $side($record, true))
                    ->description(fn (ReportLink $record): string => $origin($record, true))
                    ->wrap(),
                TextColumn::make('target')
                    ->label(__('report_links.target'))
                    ->state(fn (ReportLink $record): string => $side($record, false))
                    ->description(fn (ReportLink $record): string => $origin($record, false))
                    ->wrap(),
                TextColumn::make('updated_at')->label(__('common.changed'))->dateTime('d.m.Y H:i')->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
