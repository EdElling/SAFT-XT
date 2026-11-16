<?php

declare(strict_types=1);

namespace Saft\LagerUmbuchung;

use App\Models\DynamicRecord;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\Events\RecordDeleted;
use App\Plugins\Api\Events\RecordDeleting;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\Manual;
use App\Plugins\Api\RecordListener;
use App\Plugins\MenuSuggestion;
use App\Plugins\SaftPlugin;
use Filament\Actions\Action;
use Saft\LagerUmbuchung\Pages\Umbuchung;

/** Beispiel-Plugin: eine Fachmaske „Umbuchung“ für die Lager-Demo (Seite unter src/Pages wird automatisch gefunden). */
final class LagerUmbuchungPlugin extends SaftPlugin
{
    public const string ID = 'lager-umbuchung';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('lager-umbuchung::umbuchung.plugin');
    }

    public function description(): string
    {
        return __('lager-umbuchung::umbuchung.description');
    }

    public function permissions(): array
    {
        return ['execute' => __('lager-umbuchung::umbuchung.permission')];
    }

    /** Tabellen und Felder der Lager-Demo, ohne die das Plugin nicht arbeiten kann. */
    public function requiredTables(): array
    {
        return [
            StockTransfer::ARTICLES => ['bezeichnung'],
            StockTransfer::LOCATIONS => ['bezeichnung'],
            StockTransfer::STOCKS => ['artikel_id', 'lagerort_id', 'menge'],
            StockTransfer::MOVEMENTS => ['artikel_id', 'lagerort_id', 'art', 'menge', 'zeitpunkt'],
        ];
    }

    /**
     * Lagerbewegungen buchen den Bestand (StockLedger), Bestände sind nur so änderbar (StockGuard); Warnung, wenn ein
     * Bestand unter den Mindestbestand des Artikels sinkt (MinimumStockAlert).
     */
    public function recordListeners(): array
    {
        $ledger = static fn (string $method): \Closure => static fn ($event) => app(StockLedger::class)->{$method}($event);

        return [
            new RecordListener(StockTransfer::MOVEMENTS, RecordSaving::class, $ledger('saving')),
            new RecordListener(StockTransfer::MOVEMENTS, RecordSaved::class, $ledger('saved')),
            new RecordListener(StockTransfer::MOVEMENTS, RecordDeleting::class, $ledger('deleting')),
            new RecordListener(StockTransfer::MOVEMENTS, RecordDeleted::class, $ledger('deleted')),
            new RecordListener(StockTransfer::STOCKS, RecordSaving::class, StockGuard::class),
            new RecordListener(StockTransfer::STOCKS, RecordDeleting::class, StockGuard::class),
            new RecordListener(StockTransfer::STOCKS, RecordSaved::class, MinimumStockAlert::class),
        ];
    }

    /** Knopf „Umbuchen“ je Zeile der Tabelle Bestände: öffnet die Maske mit Artikel und Quellort. */
    public function dataActions(): array
    {
        return [new DataAction(StockTransfer::STOCKS, DataAction::RECORD, static fn (): Action => Action::make('lagerUmbuchung')
            ->label(__('lager-umbuchung::umbuchung.submit'))
            ->icon('heroicon-o-arrows-right-left')
            ->iconButton()
            ->tooltip(fn (DynamicRecord $record): string => (int) $record->getAttribute('menge') > 0
                ? __('lager-umbuchung::umbuchung.submit') : __('lager-umbuchung::umbuchung.no_stock_here'))
            ->visible(static fn (): bool => Umbuchung::canAccess())
            // Ohne Bestand ausgegraut statt ausgeblendet: die Knöpfe der Zeilen bleiben untereinander.
            ->disabled(fn (DynamicRecord $record): bool => (int) $record->getAttribute('menge') <= 0)
            ->url(fn (DynamicRecord $record): string => Umbuchung::getUrl([
                'artikel' => $record->getAttribute('artikel_id'),
                'von' => $record->getAttribute('lagerort_id'),
            ])))];
    }

    /** Benutzerhandbuch (docs/manual/<sprache>.md): für alle, die umbuchen oder Bewegungen bzw. Bestände sehen dürfen. */
    public function manuals(): array
    {
        return [new Manual(
            id: 'manual',
            title: ['de' => 'Lager-Umbuchung', 'en' => 'Stock transfer', 'fr' => 'Transfert de stock', 'it' => 'Trasferimento di magazzino'],
            path: 'docs/manual',
            permissions: [$this->permissionName('execute'), 'data.'.StockTransfer::MOVEMENTS.'.view', 'data.'.StockTransfer::STOCKS.'.view'],
        )];
    }

    public function menuSuggestions(): array
    {
        return [
            new MenuSuggestion(
                route: Umbuchung::ROUTE,
                label: ['de' => 'Umbuchung', 'en' => 'Transfer', 'fr' => 'Transfert', 'it' => 'Trasferimento'],
                icon: 'heroicon-o-arrows-right-left',
                permission: $this->permissionName('execute'),
                group: ['de' => 'Lager (Demo)', 'en' => 'Warehouse (demo)', 'fr' => 'Entrepôt (démo)', 'it' => 'Magazzino (demo)'],
                sortOrder: 5,
            ),
        ];
    }
}
