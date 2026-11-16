<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities\Pages;

use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\Activities\ActivityResource;
use Filament\Resources\Pages\ViewRecord;

/** Detail eines Protokolleintrags: in Formularbreite, lange Werte brechen um. */
class ViewActivity extends ViewRecord
{
    use LimitsFormWidth;

    protected static string $resource = ActivityResource::class;
}
