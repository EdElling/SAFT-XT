<?php

declare(strict_types=1);

namespace App\Work;

use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Models\User;
use App\Models\WorkItem;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\App;

/**
 * Benachrichtigt die startende Person (Glocke), wenn ihre Arbeit fertig oder fehlgeschlagen ist und sie nicht im
 * Browser zugesehen hat – sofort, ohne Queue, in ihrer Sprache.
 */
final class WorkNotifier
{
    public function concluded(WorkItem $item): void
    {
        $user = $item->user_id === null ? null : User::query()->find($item->user_id);

        if ($user === null || ! $user->is_active || ! in_array($item->status, [WorkItem::DONE, WorkItem::FAILED], true)) {
            return;
        }

        $previous = App::getLocale();

        try {
            App::setLocale($user->locale ?: (string) config('app.locale'));
            $notification = Notification::make()
                ->title(__('work.notifications.'.$item->status, ['work' => $item->typeLabel()]))
                ->body((string) $item->message)
                ->actions([
                    Action::make('open')->label(__('work.notifications.open'))
                        ->url(WorkItemResource::getUrl('view', ['record' => $item], panel: 'admin')),
                ]);

            // notifyNow statt sendToDatabase: Filaments Datenbank-Benachrichtigung ist ShouldQueue (siehe TaskNotifier).
            $user->notifyNow(($item->status === WorkItem::DONE ? $notification->success() : $notification->danger())->toDatabase());
        } finally {
            App::setLocale($previous);
        }
    }
}
