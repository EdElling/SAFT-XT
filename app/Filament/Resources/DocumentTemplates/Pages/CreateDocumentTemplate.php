<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates\Pages;

use App\Filament\Resources\DocumentTemplates\Concerns\AssistsDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Concerns\PreviewsDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Models\DocumentTemplate;
use Filament\Resources\Pages\CreateRecord;

class CreateDocumentTemplate extends CreateRecord
{
    use AssistsDocumentTemplate;
    use PreviewsDocumentTemplate;

    protected static string $resource = DocumentTemplateResource::class;

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof DocumentTemplate) {
            $this->notifyTemplateWarnings($record);
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
