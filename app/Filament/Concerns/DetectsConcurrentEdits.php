<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\Activity;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Optimistische Sperre für Bearbeiten-Seiten der Verwaltung (EditRecord; Fundament 2, F2): merkt updated_at beim Füllen
 * des Formulars und prüft vor dem Speichern, ob der Datensatz inzwischen geändert wurde. Dann bleibt die Seite mit der
 * Meldung „inzwischen geändert“ stehen – „Neu laden“ holt den aktuellen Stand ins Formular, „Trotzdem speichern“
 * speichert die eigenen Eingaben darüber. Wer geändert hat, steht im Änderungsprotokoll.
 *
 * Nach dem Speichern gilt der eigene Stand als gelesen, sonst meldete die Seite beim zweiten Speichern ohne Neuladen die
 * eigene Änderung als fremde. Dafür hängt sich der Trait an rememberData() von Filament, das nach dem Füllen und nach
 * jedem erfolgreichen Speichern läuft (nicht nach Halt) – afterSave() ist in mehreren Seiten und Traits schon belegt.
 */
trait DetectsConcurrentEdits
{
    public ?string $loadedVersion = null;

    protected function afterFill(): void
    {
        $this->loadedVersion = $this->currentVersion();
    }

    /** Filament merkt sich hier den „sauberen“ Formularstand (nach dem Füllen und nach dem Speichern): derselbe Moment für die Version. */
    protected function rememberData(): void
    {
        parent::rememberData();
        $this->loadedVersion = $this->currentVersion();
    }

    /** Vor der Prüfung der Eingaben (Filament-Hook beforeValidate; beforeSave ist in einigen Seiten schon belegt). */
    protected function beforeValidate(): void
    {
        $current = $this->currentVersion();

        if ($this->loadedVersion === null || $current === null || $current === $this->loadedVersion) {
            return;
        }

        $this->notifyConcurrentEdit($current);

        throw new Halt;
    }

    #[On('stale-record-reload')]
    public function reloadAfterStaleRecord(string $component): void
    {
        if ($component !== $this->getId()) {
            return;
        }

        $this->record = $this->getRecord()->fresh() ?? $this->getRecord();
        $this->fillForm();
    }

    #[On('stale-record-overwrite')]
    public function overwriteStaleRecord(string $component, string $version): void
    {
        if ($component !== $this->getId()) {
            return;
        }

        $this->loadedVersion = $version;
        $this->save();
    }

    /** updated_at des gespeicherten Standes; null, wenn das Model keinen Zeitstempel führt. */
    private function currentVersion(): ?string
    {
        $fresh = $this->getRecord()->fresh();
        $at = $fresh instanceof Model ? $fresh->getAttribute('updated_at') : null;

        return match (true) {
            $at instanceof \DateTimeInterface => $at->format('Y-m-d H:i:s'),
            $at === null => null,
            default => (string) $at,
        };
    }

    private function notifyConcurrentEdit(string $current): void
    {
        $record = $this->getRecord();
        $last = Activity::query()->where('subject_type', $record->getMorphClass())->where('subject_id', $record->getKey())->orderByDesc('id')->first();
        $causer = $last?->causer;
        $message = $causer instanceof Model && $last?->created_at !== null
            ? __('errors.rules.stale_record', ['user' => (string) $causer->getAttribute('name'), 'time' => $last->created_at->format('d.m.Y H:i')])
            : __('errors.rules.stale_record_unknown');

        Notification::make()
            ->danger()
            ->persistent()
            ->title(__('errors.titles.stale_record'))
            ->body($message.' '.__('data.stale.hint'))
            ->actions([
                Action::make('reload')->label(__('data.stale.reload'))->button()
                    ->dispatch('stale-record-reload', ['component' => $this->getId()])->close(),
                Action::make('overwrite')->label(__('data.stale.overwrite'))->color('gray')
                    ->dispatch('stale-record-overwrite', ['component' => $this->getId(), 'version' => $current])->close(),
            ])
            ->send();
    }
}
