<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung;

use App\Models\User;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Saft;
use Filament\Notifications\Notification;

/**
 * Listener: Hinweis (kein Verbot), wenn ein Kurs mehr Teilnehmende zulässt, als sein Raum Plätze hat. Nur beim Anlegen
 * und wenn sich Raum oder Höchstzahl ändern.
 */
final class RoomCapacityHint
{
    public function __invoke(RecordSaved $event): void
    {
        $user = auth()->user();
        $room = $event->value('raum');

        if (! $user instanceof User || blank($room) || (! $event->creating() && $event->value('raum') == $event->previous('raum')
            && $event->value('max_teilnehmer') == $event->previous('max_teilnehmer'))) {
            return;
        }

        $record = Saft::table(School::ROOMS)->withoutAuthorization()->find((int) $room);
        $places = $record?->getAttribute('plaetze');
        $max = (int) $event->value('max_teilnehmer');

        if ($places === null || $max <= (int) $places) {
            return;
        }

        Saft::notify($user, Notification::make()
            ->warning()
            ->title(__('musikschule-planung::planung.capacity.title'))
            ->body(__('musikschule-planung::planung.capacity.body', [
                'course' => (string) $event->value('bezeichnung'),
                'max' => $max,
                'room' => (string) $record->getAttribute('bezeichnung'),
                'places' => (int) $places,
            ])));
    }
}
