<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates\Pages;

use App\Filament\Concerns\HasAppTabs;
use App\Filament\Concerns\HeaderActionsInTable;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentTemplates extends ListRecords
{
    use HasAppTabs;
    use HeaderActionsInTable;

    protected static string $resource = DocumentTemplateResource::class;

    public function getSubheading(): ?string
    {
        return __('documents.templates.description');
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
