<?php

declare(strict_types=1);

namespace Saft\LagerUmbuchung;

use App\Models\DynamicRecord;
use App\Plugins\Api\DataTable;
use App\Plugins\Api\Events\RecordDeleted;
use App\Plugins\Api\Events\RecordDeleting;
use App\Plugins\Api\Events\RecordEvent;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\RecordRejected;
use App\Plugins\Api\Saft;
use Illuminate\Support\Carbon;

/**
 * „Lagerbewegung bucht Bestand“: Jede neue, geänderte, gelöschte oder wiederhergestellte Lagerbewegung passt den
 * Bestand am betreffenden Lagerort an (Menge mit Vorzeichen: + Zugang, − Abgang; bei Ausgang und Eingang setzt der
 * Listener das Vorzeichen selbst) und legt ihn bei Bedarf an.
 * Würde ein Bestand dadurch negativ, lehnt der Listener die Änderung ab – vor dem Speichern bzw. Löschen.
 *
 * Gebucht wird ohne Rechteprüfung: Wer Bewegungen erfassen darf, soll den Bestand nicht selbst bearbeiten müssen.
 */
final class StockLedger
{
    /** @var array<int, array<string, array{0: int, 1: int, 2: int}>> beim Prüfen des Löschens berechnet, je Datensatz */
    private static array $pendingRemovals = [];

    /** Schreibt der Listener gerade selbst in Bestände? Nur dann lässt StockGuard Änderungen zu. */
    private static bool $writing = false;

    /** Bewegungen ohne Buchung speichern (Abgleich bestehender Daten). */
    private static bool $silent = false;

    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /** Läuft ein Abgleich bestehender Daten (keine Buchungen, keine Meldungen)? */
    public static function isAdjusting(): bool
    {
        return self::$silent;
    }

    /**
     * Bestände direkt schreiben bzw. Bewegungen ohne Buchung speichern – nur für den Abgleich bestehender Daten.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function adjusting(\Closure $callback): mixed
    {
        [$writing, $silent] = [self::$writing, self::$silent];
        self::$writing = self::$silent = true;

        try {
            return $callback();
        } finally {
            [self::$writing, self::$silent] = [$writing, $silent];
        }
    }

    public function saving(RecordSaving $event): void
    {
        if (self::$silent) {
            return;
        }

        // Vorzeichen nach Art: Ausgang immer negativ, Eingang immer positiv (Inventur und Umbuchung wie eingegeben).
        $quantity = (int) $event->value('menge');
        $signed = match ($event->value('art')) {
            'Ausgang' => -abs($quantity),
            'Eingang' => abs($quantity),
            default => $quantity,
        };

        if ($signed !== $quantity) {
            $event->record->setAttribute('menge', $signed);
        }

        $this->check($this->changes($event, removing: false));
    }

    public function saved(RecordSaved $event): void
    {
        if (self::$silent) {
            return;
        }

        $this->apply($this->changes($event, removing: false));

        // Inventur: Datum der letzten Zählung am Bestand festhalten.
        if ($event->value('art') === 'Inventur' && in_array('letzte_zaehlung', $this->stocks()->fieldNames(), true)) {
            $stock = $this->stock((int) $event->value('artikel_id'), (int) $event->value('lagerort_id'));

            if ($stock !== null) {
                $this->write(fn () => $this->stocks()->update($stock, ['letzte_zaehlung' => Carbon::parse($event->value('zeitpunkt'))->toDateString()]));
            }
        }
    }

    public function deleting(RecordDeleting $event): void
    {
        if (self::$silent) {
            return;
        }

        // Liegt die Bewegung schon im Papierkorb (endgültiges Löschen), ist sie bereits ausgebucht.
        $trashed = $event->value('deleted_at') !== null;
        $changes = $trashed ? [] : $this->changes($event, removing: true);
        $this->check($changes);
        self::$pendingRemovals[spl_object_id($event->record)] = $changes;
    }

    public function deleted(RecordDeleted $event): void
    {
        $id = spl_object_id($event->record);

        if (! self::$silent) {
            $this->apply(self::$pendingRemovals[$id] ?? []);
        }

        unset(self::$pendingRemovals[$id]);
    }

    /**
     * Auswirkung der Bewegung auf die Bestände.
     *
     * @return array<string, array{0: int, 1: int, 2: int}> „artikel|ort“ => [Artikel, Lagerort, Änderung]
     */
    private function changes(RecordEvent $event, bool $removing): array
    {
        $changes = [];
        $add = static function (mixed $article, mixed $location, mixed $quantity) use (&$changes): void {
            if ($article === null || $location === null || (int) $quantity === 0) {
                return;
            }

            $key = (int) $article.'|'.(int) $location;
            $changes[$key] ??= [(int) $article, (int) $location, 0];
            $changes[$key][2] += (int) $quantity;
        };

        if ($removing) {
            $add($event->value('artikel_id'), $event->value('lagerort_id'), -(int) $event->value('menge'));

            return $changes;
        }

        $restored = $event->previous('deleted_at') !== null && $event->value('deleted_at') === null;
        $new = $event instanceof RecordSaving ? $event->creating() : ($event instanceof RecordSaved && $event->creating());

        if (! $new && ! $restored) {
            // Änderung: alten Stand zurücknehmen, neuen buchen.
            $add($event->previous('artikel_id'), $event->previous('lagerort_id'), -(int) $event->previous('menge'));
        }

        $add($event->value('artikel_id'), $event->value('lagerort_id'), (int) $event->value('menge'));

        return array_filter($changes, static fn (array $change): bool => $change[2] !== 0);
    }

    /** @param  array<string, array{0: int, 1: int, 2: int}>  $changes */
    private function check(array $changes): void
    {
        foreach ($changes as [$article, $location, $delta]) {
            $current = (int) $this->stock($article, $location)?->getAttribute('menge');

            if ($current + $delta < 0) {
                throw new RecordRejected(__('lager-umbuchung::umbuchung.errors.negative', [
                    'article' => (string) Saft::table(StockTransfer::ARTICLES)->withoutAuthorization()->find($article)?->getAttribute('bezeichnung'),
                    'location' => (string) Saft::table(StockTransfer::LOCATIONS)->withoutAuthorization()->find($location)?->getAttribute('bezeichnung'),
                    'available' => $current,
                ]));
            }
        }
    }

    /** @param  array<string, array{0: int, 1: int, 2: int}>  $changes */
    private function apply(array $changes): void
    {
        foreach ($changes as [$article, $location, $delta]) {
            $stock = $this->stock($article, $location);

            $this->write(fn () => $stock === null
                ? $this->stocks()->create(['artikel_id' => $article, 'lagerort_id' => $location, 'menge' => $delta])
                : $this->stocks()->update($stock, ['menge' => (int) $stock->getAttribute('menge') + $delta]));
        }
    }

    private function write(\Closure $callback): void
    {
        $writing = self::$writing;
        self::$writing = true;

        try {
            $callback();
        } finally {
            self::$writing = $writing;
        }
    }

    private function stock(int $article, int $location): ?DynamicRecord
    {
        return $this->stocks()->query()->where('artikel_id', $article)->where('lagerort_id', $location)->first();
    }

    private function stocks(): DataTable
    {
        return Saft::table(StockTransfer::STOCKS)->withoutAuthorization();
    }
}
