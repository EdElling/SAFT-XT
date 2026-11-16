<?php

declare(strict_types=1);

namespace Saft\LagerTafel;

use App\Models\DynamicRecord;
use App\Plugins\Api\Saft;

/**
 * Lesezugriff auf die Lager-Demo für Lagerspiegel, Lagertafel und Kachel – immer über Saft::table(…), also mit den
 * Datenrechten der angemeldeten Person.
 */
final class Warehouse
{
    public const string ARTICLES = 'lager_artikel';

    public const string LOCATIONS = 'lager_orte';

    public const string STOCKS = 'lager_bestaende';

    /** Sind alle Tabellen und Felder aus requiredTables() vorhanden? */
    public function available(): bool
    {
        return $this->missing() === [];
    }

    /** @return list<string> was fehlt, als lesbare Meldungen */
    public function missing(): array
    {
        return Saft::plugin(LagerTafelPlugin::ID)->missingDependencies();
    }

    /** Darf die angemeldete Person Artikel, Lagerorte und Bestände lesen? */
    public function canRead(): bool
    {
        return Saft::table(self::ARTICLES)->can('view')
            && Saft::table(self::LOCATIONS)->can('view')
            && Saft::table(self::STOCKS)->can('view');
    }

    /** Hat die Tabelle Artikel das optionale Feld „Mindestbestand“? */
    public function hasMinimum(): bool
    {
        return in_array('mindestbestand', Saft::table(self::ARTICLES)->fieldNames(), true);
    }

    /** @return array<int, string> Lagerorte: id => Bezeichnung, alphabetisch */
    public function locations(): array
    {
        return Saft::table(self::LOCATIONS)->query()->orderBy('bezeichnung')->get()
            ->mapWithKeys(static fn (DynamicRecord $l): array => [(int) $l->getKey() => (string) $l->getAttribute('bezeichnung')])
            ->all();
    }

    /** @return array<int, string> Artikel: id => „Nummer – Bezeichnung“, alphabetisch */
    public function articles(): array
    {
        return Saft::table(self::ARTICLES)->query()->orderBy('bezeichnung')->get()
            ->mapWithKeys(static fn (DynamicRecord $a): array => [
                (int) $a->getKey() => trim($a->getAttribute('artikelnummer').' – '.$a->getAttribute('bezeichnung'), ' –'),
            ])
            ->all();
    }

    /**
     * Alle Bestände in einer Abfrage.
     *
     * @return array<int, array<int, int>> Artikel-id => [Lagerort-id => Menge]
     */
    public function matrix(): array
    {
        $matrix = [];

        foreach (Saft::table(self::STOCKS)->query()->get(['artikel_id', 'lagerort_id', 'menge']) as $stock) {
            $article = (int) $stock->getAttribute('artikel_id');
            $location = (int) $stock->getAttribute('lagerort_id');
            $matrix[$article][$location] = ($matrix[$article][$location] ?? 0) + (int) $stock->getAttribute('menge');
        }

        return $matrix;
    }

    /** @return array<int, int> Bestände eines Artikels: Lagerort-id => Menge (auch 0) */
    public function stocksOf(int $article): array
    {
        return $this->matrix()[$article] ?? [];
    }

    /** @return list<int> Artikel, deren Gesamtbestand unter dem Mindestbestand liegt (leer ohne das Feld) */
    public function belowMinimum(): array
    {
        if (! $this->hasMinimum()) {
            return [];
        }

        $matrix = $this->matrix();

        return Saft::table(self::ARTICLES)->query()->whereNotNull('mindestbestand')->get(['id', 'mindestbestand'])
            ->filter(static fn (DynamicRecord $a): bool => array_sum($matrix[(int) $a->getKey()] ?? []) < (int) $a->getAttribute('mindestbestand'))
            ->map(static fn (DynamicRecord $a): int => (int) $a->getKey())
            ->values()
            ->all();
    }
}
