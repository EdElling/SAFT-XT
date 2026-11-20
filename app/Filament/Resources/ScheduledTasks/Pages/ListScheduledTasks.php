<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduledTasks\Pages;

use App\Filament\Concerns\HasAppTabs;
use App\Filament\Resources\ScheduledTasks\ScheduledTaskResource;
use App\Models\ScheduledTask;
use App\Tasks\TaskRunner;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListScheduledTasks extends ListRecords
{
    use HasAppTabs;

    protected function appTabsWithSystem(): bool
    {
        return true;
    }

    protected static string $resource = ScheduledTaskResource::class;

    public function getSubheading(): string|HtmlString|null
    {
        $text = e(__('tasks.description'));

        if (! app(TaskRunner::class)->schedulerAlive() && ScheduledTask::query()->where('is_active', true)->exists()) {
            $text .= '<br><span class="text-warning-600 dark:text-warning-400 font-medium" data-scheduler-missing>⚠ '.e(__('tasks.scheduler_missing')).'</span>';
        }

        return new HtmlString($text);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
