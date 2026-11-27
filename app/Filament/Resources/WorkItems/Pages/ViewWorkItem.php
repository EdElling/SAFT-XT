<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkItems\Pages;

use App\DynamicData\Ui\BulkUndoActions;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Models\BulkChange;
use App\Models\WorkItem;
use App\Work\Types\BulkRecordsWork;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/** Eine Arbeit: Fortschritt (läuft im Browser der startenden Person weiter), Ergebnis, Angaben. */
class ViewWorkItem extends ViewRecord
{
    use LimitsFormWidth;

    protected static string $resource = WorkItemResource::class;

    public function getTitle(): string
    {
        return $this->getRecord() instanceof WorkItem ? $this->getRecord()->typeLabel() : parent::getTitle();
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        $undo = BulkUndoActions::notificationAction($this->bulkChange());

        return $undo === null ? [] : [$undo->button()->icon('heroicon-o-arrow-uturn-left')->color('warning')];
    }

    /** Kopf einer fertigen Sammeländerung (B-133 S3), sonst null. */
    private function bulkChange(): ?BulkChange
    {
        $item = $this->getRecord();

        if (! $item instanceof WorkItem || $item->type !== BulkRecordsWork::KEY || $item->status !== WorkItem::DONE || blank($item->result['run'] ?? null)) {
            return null;
        }

        return BulkChange::query()->with(['custom_table', 'undoneBy'])->where('run', (string) $item->result['run'])->first();
    }
}
