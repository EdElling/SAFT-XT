<?php

declare(strict_types=1);

namespace App\Listeners;

use App\DynamicData\DynamicDataNamespace;
use App\Models\DynamicRecord;
use Illuminate\Database\Events\DatabaseRefreshed;

/**
 * migrate:fresh löscht nur Tabellen im search_path der Standardverbindung. Ohne diesen
 * Listener blieben Tabellen in app_data ohne (dann gelöschte) Metadaten zurück.
 */
class ResetAppDataNamespace
{
    public function __construct(private readonly DynamicDataNamespace $namespace) {}

    public function handle(DatabaseRefreshed $event): void
    {
        if ($event->database !== null && $event->database !== config('database.default')) {
            return;
        }

        $this->namespace->reset();
        DynamicRecord::forget();
    }
}
