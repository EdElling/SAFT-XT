<?php

declare(strict_types=1);

namespace Saft\LagerUmbuchung;

use App\Models\DynamicRecord;
use App\Plugins\Api\Saft;

/**
 * Umbuchung zwischen zwei Lagerorten: zwei Lagerbewegungen der Art „Umbuchung“ (−/+) schreiben – alles oder nichts.
 * Die Bestände an beiden Orten führt der Listener StockLedger daraus nach. Über die Schnittstelle von SAFT
 * gelten dabei die Datenrechte der angemeldeten Person und die Prüfregeln des Table-Designers; jede Änderung landet
 * im Änderungsprotokoll.
 */
final class StockTransfer
{
    public const string ARTICLES = 'lager_artikel';

    public const string LOCATIONS = 'lager_orte';

    public const string STOCKS = 'lager_bestaende';

    public const string MOVEMENTS = 'lager_bewegungen';

    /** Läuft gerade eine Umbuchung? Der Gesamtbestand bleibt gleich – MinimumStockAlert meldet dann nichts. */
    public static bool $running = false;

    /** Sind die Tabellen und Felder der Lager-Demo vorhanden, die das Plugin braucht (requiredTables())? */
    public function available(): bool
    {
        return $this->missing() === [];
    }

    /** @return list<string> was fehlt */
    public function missing(): array
    {
        return Saft::plugin(LagerUmbuchungPlugin::ID)->missingDependencies();
    }

    /** @return array<int, string> Artikel: id => „Nummer – Bezeichnung“ */
    public function articles(): array
    {
        return Saft::table(self::ARTICLES)->query()->orderBy('bezeichnung')->get()
            ->mapWithKeys(static fn (DynamicRecord $a): array => [(int) $a->getKey() => trim($a->getAttribute('artikelnummer').' – '.$a->getAttribute('bezeichnung'), ' –')])
            ->all();
    }

    /** @return array<int, string> Lagerorte: id => Bezeichnung */
    public function locations(): array
    {
        return Saft::table(self::LOCATIONS)->query()->orderBy('bezeichnung')->pluck('bezeichnung', 'id')
            ->mapWithKeys(static fn (mixed $label, mixed $id): array => [(int) $id => (string) $label])->all();
    }

    /** @return array<int, int> Lagerorte mit Bestand dieses Artikels: id => Menge (> 0) */
    public function stocks(int $article): array
    {
        return Saft::table(self::STOCKS)->query()->where('artikel_id', $article)->where('menge', '>', 0)->get()
            ->mapWithKeys(static fn (DynamicRecord $s): array => [(int) $s->getAttribute('lagerort_id') => (int) $s->getAttribute('menge')])
            ->all();
    }

    public function stock(int $article, int $location): int
    {
        return (int) Saft::table(self::STOCKS)->query()->where('artikel_id', $article)->where('lagerort_id', $location)->sum('menge');
    }

    /** @return list<string> Tabellen, für die der angemeldeten Person Datenrechte fehlen */
    public function missingRights(): array
    {
        $missing = [];

        foreach ([self::ARTICLES => ['view'], self::LOCATIONS => ['view'], self::STOCKS => ['view', 'create', 'update'], self::MOVEMENTS => ['create']] as $name => $actions) {
            $table = Saft::table($name);

            if (array_filter($actions, static fn (string $action): bool => ! $table->can($action)) !== []) {
                $missing[] = $table->label();
            }
        }

        return $missing;
    }

    /** @throws TransferRejected wenn die Umbuchung nicht möglich ist */
    public function transfer(int $article, int $from, int $to, int $quantity, ?string $note = null): void
    {
        if ($from === $to) {
            throw new TransferRejected(__('lager-umbuchung::umbuchung.errors.same_location'));
        }

        if ($quantity < 1) {
            throw new TransferRejected(__('lager-umbuchung::umbuchung.errors.quantity'));
        }

        $stocks = Saft::table(self::STOCKS);
        $movements = Saft::table(self::MOVEMENTS);
        self::$running = true;

        try {
            // Die Bestände führt der Listener StockLedger aus den beiden Bewegungen nach.
            Saft::transaction(function () use ($stocks, $movements, $article, $from, $to, $quantity, $note): void {
                $available = (int) $stocks->query()->where('artikel_id', $article)->where('lagerort_id', $from)->lockForUpdate()->value('menge');

                if ($available < $quantity) {
                    throw new TransferRejected(__('lager-umbuchung::umbuchung.errors.not_enough', ['available' => $available]));
                }

                $now = now();

                foreach ([[$from, -$quantity], [$to, $quantity]] as [$location, $signed]) {
                    $movements->create([
                        'artikel_id' => $article,
                        'lagerort_id' => $location,
                        'art' => 'Umbuchung',
                        'menge' => $signed,
                        'zeitpunkt' => $now,
                        'bemerkung' => filled($note) ? $note : null,
                    ]);
                }
            });
        } finally {
            self::$running = false;
        }
    }
}
