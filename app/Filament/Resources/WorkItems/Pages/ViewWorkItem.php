<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkItems\Pages;

use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Models\WorkItem;
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
}
