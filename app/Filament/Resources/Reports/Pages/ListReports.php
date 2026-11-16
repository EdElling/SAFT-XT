<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReports extends ListRecords
{
    // Keine feste Formularbreite: Die Tabelle ist (wie alle Listen) so breit wie ihre Spalten, höchstens so breit
    // wie das Fenster; Name/Satz und Beschreibung brechen um. Eine feste Grenze ließ sie seitlich scrollen.
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
