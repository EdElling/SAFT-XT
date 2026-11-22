<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTemplates\Pages;

use App\Filament\Resources\DocumentTemplates\Concerns\AssistsDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Concerns\PreviewsDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Models\DocumentTemplate;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDocumentTemplate extends EditRecord
{
    use AssistsDocumentTemplate;
    use PreviewsDocumentTemplate;

    protected static string $resource = DocumentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record instanceof DocumentTemplate) {
            $this->notifyTemplateWarnings($record->load('customTable'));
        }
    }
}
