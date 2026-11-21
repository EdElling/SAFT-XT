<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung;

use App\Models\DynamicRecord;
use App\Models\User;
use App\Plugins\Api\Events\RecordDeleted;
use App\Plugins\Api\Events\RecordEvent;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Saft;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Listener: Wird in einem Kurs ein Platz frei und gibt es Wartende, erhält die handelnde Person einen Hinweis in der
 * Glocke mit den Wartenden und einem Link auf die Anmeldungen des Kurses. Ein Platz wird frei, wenn eine aktive
 * Anmeldung abgemeldet bzw. auf die Warteliste gesetzt oder gelöscht wird, oder wenn die Höchstzahl des Kurses steigt.
 * Nachgerückt wird nicht automatisch – das klärt die Schule mit den Familien.
 */
final class WaitingListHint
{
    public function __invoke(RecordEvent $event): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ($course = $this->course($event)) === null) {
            return;
        }

        $enrolments = Saft::table(School::ENROLMENTS)->withoutAuthorization()->query()->where('kurs', $course->getKey())->get();
        $free = (int) $course->getAttribute('max_teilnehmer') - $enrolments->where('status', 'aktiv')->count();
        $waiting = $enrolments->where('status', SemesterRollover::WAITING)->sortBy([['datum', 'asc'], ['id', 'asc']]);

        if ($free <= 0 || $waiting->isEmpty()) {
            return;
        }

        $students = app(School::class)->students();
        $names = $waiting->take(5)->map(static fn (DynamicRecord $e): string => ($students[(int) $e->getAttribute('schueler')] ?? '?')
            .' ('.__('musikschule-planung::planung.waiting.since', ['date' => date('d.m.Y', (int) strtotime(School::date($e, 'datum')))]).')')->implode(', ');

        Saft::notify($user, Notification::make()
            ->info()
            ->title(trans_choice('musikschule-planung::planung.waiting.title', $free, ['course' => (string) $course->getAttribute('bezeichnung'), 'count' => $free]))
            ->body(__('musikschule-planung::planung.waiting.body', ['count' => $waiting->count(), 'names' => $names]))
            ->actions([Action::make('open')->label(__('musikschule-planung::planung.waiting.open'))->url(Saft::table(School::COURSES)->url($course))]));
    }

    /** Der betroffene Kurs, wenn das Ereignis einen Platz freigeben kann. */
    private function course(RecordEvent $event): ?DynamicRecord
    {
        $courses = Saft::table(School::COURSES)->withoutAuthorization();

        if ($event->table === School::COURSES) {
            $raised = $event instanceof RecordSaved && ! $event->creating() && (int) $event->value('max_teilnehmer') > (int) $event->previous('max_teilnehmer');

            return $raised ? $event->record : null;
        }

        $wasActive = $event instanceof RecordDeleted
            ? $event->value('status') === 'aktiv'
            : $event instanceof RecordSaved && ! $event->creating() && $event->previous('status') === 'aktiv' && $event->value('status') !== 'aktiv';

        return $wasActive ? $courses->find((int) $event->value('kurs')) : null;
    }
}
