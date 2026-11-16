<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduledTasks\Pages;

use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Resources\ScheduledTasks\ScheduledTaskResource;
use App\Filament\Resources\ScheduledTasks\Schemas\ScheduledTaskForm;
use App\Tasks\Frequency;
use App\Tasks\TaskRegistry;
use Filament\Resources\Pages\CreateRecord;

class CreateScheduledTask extends CreateRecord
{
    use LimitsFormWidth;

    protected static string $resource = ScheduledTaskResource::class;

    /** Vorbelegung aus dem Table-Connector (?type=snapshot_refresh&table=<id>). */
    protected function afterFill(): void
    {
        $type = request()->query('type');
        $table = request()->query('table');

        if (! is_string($type) || ! app(TaskRegistry::class)->has($type) || ! app(TaskRegistry::class)->get($type)->creatable()) {
            return;
        }

        $this->form->fill([
            'type' => $type,
            'frequency' => Frequency::Daily->value,
            'at_time' => '06:00',
            'minute' => 0,
            'weekday' => 1,
            'is_active' => true,
            'parameters' => is_numeric($table) ? ['table_id' => (int) $table] : [],
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...ScheduledTaskForm::toRecord($data), 'created_by' => auth()->id(), 'is_system' => false];
    }
}
