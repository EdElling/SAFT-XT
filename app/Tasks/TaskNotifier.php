<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Filament\Resources\ScheduledTasks\ScheduledTaskResource;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\App;

/** Benachrichtigt alle aktiven Super-Admins (Glocke), jeweils in ihrer Sprache – sofort, ohne Queue. */
final class TaskNotifier
{
    public function failed(ScheduledTask $task, TaskRun $run): void
    {
        $this->send($task, $run, 'failed');
    }

    public function recovered(ScheduledTask $task, TaskRun $run): void
    {
        $this->send($task, $run, 'recovered');
    }

    private function send(ScheduledTask $task, TaskRun $run, string $kind): void
    {
        $previous = App::getLocale();
        // Vorgabe vor der Schleife festhalten: App::setLocale() ändert auch config('app.locale') – sonst bekäme eine
        // Person ohne eigene Sprache die Sprache der zuvor benachrichtigten (B-059).
        $default = (string) config('app.locale');

        try {
            foreach (User::role(User::ROLE_SUPER_ADMIN)->where('is_active', true)->orderBy('id')->get() as $admin) {
                App::setLocale($admin->locale ?: $default);

                $notification = Notification::make()
                    ->title(__("tasks.notifications.{$kind}", ['task' => $task->displayName()]))
                    ->body(trim($task->description().($kind === 'failed' ? "\n".$run->message : '')))
                    ->actions([
                        Action::make('open')->label(__('tasks.notifications.open'))
                            ->url(ScheduledTaskResource::getUrl('edit', ['record' => $task], panel: 'admin')),
                    ]);

                // notifyNow statt sendToDatabase: Filaments Datenbank-Benachrichtigung ist ShouldQueue, SAFT läuft ohne Queue-Worker.
                $admin->notifyNow(($kind === 'failed' ? $notification->danger() : $notification->success())->toDatabase());
            }
        } finally {
            App::setLocale($previous);
        }
    }
}
