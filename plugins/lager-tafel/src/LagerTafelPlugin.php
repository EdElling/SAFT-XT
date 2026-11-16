<?php

declare(strict_types=1);

namespace Saft\LagerTafel;

use App\Models\DynamicRecord;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\FieldRule;
use App\Plugins\Api\RecordListener;
use App\Plugins\MenuSuggestion;
use App\Plugins\SaftPlugin;
use Filament\Actions\Action;
use Saft\LagerTafel\Listeners\LockArticleNumber;
use Saft\LagerTafel\Listeners\NormalizeArticleNumber;
use Saft\LagerTafel\Pages\Lagerspiegel;
use Saft\LagerTafel\Pages\Lagertafel;
use Saft\LagerTafel\Tasks\MinimumStockReportTask;
use Saft\LagerTafel\Tasks\StocktakingReminderTask;

/**
 * Beispiel-Plugin zu den Anleitungen im Anhang A des Entwicklerhandbuchs: je Baustein ein vollständiges, getestetes
 * Beispiel an der Lager-Demo. Seiten unter src/Pages und Kacheln unter src/Widgets findet SAFT selbst.
 */
final class LagerTafelPlugin extends SaftPlugin
{
    public const string ID = 'lager-tafel';

    private const string T = 'lager-tafel::tafel.';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __(self::T.'plugin');
    }

    public function description(): string
    {
        return __(self::T.'description');
    }

    /** Zwei Rechte: lesen (Lagerspiegel, Kachel) und umbuchen (Lagertafel). Namen: lager-tafel.view, lager-tafel.transfer */
    public function permissions(): array
    {
        return [
            'view' => __(self::T.'permissions.view'),
            'transfer' => __(self::T.'permissions.transfer'),
        ];
    }

    /** Ohne diese Tabellen und Felder ruht das Plugin; „Mindestbestand“ ist optional und wird beim Zugriff geprüft. */
    public function requiredTables(): array
    {
        return [
            Warehouse::ARTICLES => ['artikelnummer', 'bezeichnung'],
            Warehouse::LOCATIONS => ['bezeichnung'],
            Warehouse::STOCKS => ['artikel_id', 'lagerort_id', 'menge'],
        ];
    }

    /** A.7 – zwei Einträge in der Gruppe der Lager-Demo, hinter „Umbuchung“ (5) des Plugins lager-umbuchung. */
    public function menuSuggestions(): array
    {
        $group = ['de' => 'Lager (Demo)', 'en' => 'Warehouse (demo)', 'fr' => 'Entrepôt (démo)', 'it' => 'Magazzino (demo)'];

        return [
            new MenuSuggestion(
                route: Lagerspiegel::ROUTE,
                label: ['de' => 'Lagerspiegel', 'en' => 'Stock matrix', 'fr' => 'Matrice des stocks', 'it' => 'Matrice delle giacenze'],
                icon: 'heroicon-o-table-cells',
                permission: $this->permissionName('view'),
                group: $group,
                sortOrder: 6,
            ),
            new MenuSuggestion(
                route: Lagertafel::ROUTE,
                label: ['de' => 'Lagertafel', 'en' => 'Stock board', 'fr' => 'Tableau des stocks', 'it' => 'Tabellone delle giacenze'],
                icon: 'heroicon-o-view-columns',
                permission: $this->permissionName('transfer'),
                group: $group,
                sortOrder: 7,
            ),
        ];
    }

    /** A.8 – zwei Arten geplanter Aufgaben; die Administration legt sie unter „Geplante Aufgaben“ an. */
    public function taskTypes(): array
    {
        return [StocktakingReminderTask::class, MinimumStockReportTask::class];
    }

    /** A.5 – Geschäftsregeln für die Tabelle Artikel, wirksam in allen Datenansichten, im Import und in der Schnittstelle. */
    public function recordListeners(): array
    {
        return [
            new RecordListener(Warehouse::ARTICLES, RecordSaving::class, NormalizeArticleNumber::class),
            new RecordListener(Warehouse::ARTICLES, RecordSaving::class, LockArticleNumber::class),
        ];
    }

    /** A.4 – Knöpfe in der bestehenden Datenansicht Artikel: über der Tabelle und in jeder Zeile. */
    public function dataActions(): array
    {
        return [
            new DataAction(Warehouse::ARTICLES, DataAction::TABLE, static fn (): Action => Action::make('lagerTafelSpiegel')
                ->label(__(self::T.'actions.matrix'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(static fn (): bool => Lagerspiegel::canAccess())
                ->url(static fn (): string => Lagerspiegel::getUrl())),
            new DataAction(Warehouse::ARTICLES, DataAction::RECORD, static fn (): Action => Action::make('lagerTafelVerteilen')
                ->label(__(self::T.'actions.board'))
                ->tooltip(__(self::T.'actions.board'))
                ->icon('heroicon-o-view-columns')
                ->iconButton()
                ->visible(static fn (): bool => Lagertafel::canAccess())
                ->url(static fn (DynamicRecord $record): string => Lagertafel::getUrl(['artikel' => $record->getKey()]))),
        ];
    }

    /** A.6 – Prüfregel „Artikelnummer (Lager-Demo)“, im Table-Designer unter „Weitere Prüfungen“ wählbar. */
    public function fieldRules(): array
    {
        return [new FieldRule(
            name: 'artikelnummer',
            label: __(self::T.'rules.article_number'),
            types: ['string'],
            check: static fn (mixed $value): ?string => ArticleNumber::isValid((string) $value)
                ? null
                : __(self::T.'rules.article_number_invalid'),
        )];
    }
}
