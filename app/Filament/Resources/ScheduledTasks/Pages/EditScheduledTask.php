<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduledTasks\Pages;

use App\Filament\Concerns\DetectsConcurrentEdits;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\ScheduledTasks\ScheduledTaskResource;
use App\Filament\Resources\ScheduledTasks\Schemas\ScheduledTaskForm;
use App\Filament\Resources\ScheduledTasks\Tables\ScheduledTasksTable;
use App\Models\ScheduledTask;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditScheduledTask extends EditRecord
{
    use DetectsConcurrentEdits;
    use LimitsFormWidth;

    protected static string $resource = ScheduledTaskResource::class;

    protected function getHeaderActions(): array
    {
        /** @var ScheduledTask $task */
        $task = $this->getRecord();

        return [
            Action::make('runNow')
                ->label(__('tasks.run_now'))
                ->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->modalDescription(__('tasks.run_now_confirm', ['task' => $task->displayName()]))
                ->authorize(fn (): bool => auth()->user()?->can('tasks.update') ?? false)
                ->action(function () use ($task): void {
                    ScheduledTasksTable::runNow($task);
                    $this->dispatch('task-ran');
                }),
            DeleteAction::make()->visible(! $task->is_system),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ScheduledTaskForm::toForm($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = ScheduledTaskForm::toRecord([...$data, 'type' => $this->getRecord()->getAttribute('type')]);
        unset($data['type']);

        return $data;
    }
}
