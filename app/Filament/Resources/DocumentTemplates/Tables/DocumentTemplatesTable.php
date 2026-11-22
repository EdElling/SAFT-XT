<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates\Tables;

use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Support\AppFilter;
use App\Filament\Support\RowIcons;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customTable', 'app']))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label(__('documents.templates.fields.name'))->searchable()->sortable()->wrap(),
                TextColumn::make('customTable.label')->label(__('documents.templates.fields.table'))->wrap(),
                IconColumn::make('is_active')->label(__('documents.templates.fields.is_active'))->boolean(),
                TextColumn::make('updated_at')->label(__('common.changed'))->dateTime('d.m.Y H:i')->toggleable(),
                AppFilter::column(),
            ])
            ->recordActions(RowIcons::of([
                EditAction::make(),
                ReplicateAction::make()
                    ->excludeAttributes(['uuid'])
                    ->mutateRecordDataUsing(static fn (array $data): array => [...$data, 'name' => __('documents.templates.copy_of', ['name' => $data['name']])])
                    ->successRedirectUrl(static fn ($replica): string => DocumentTemplateResource::getUrl('edit', ['record' => $replica])),
                DeleteAction::make(),
            ]));
    }
}
