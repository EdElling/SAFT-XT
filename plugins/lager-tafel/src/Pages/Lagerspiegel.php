<?php

declare(strict_types=1);

namespace Saft\LagerTafel\Pages;

use App\Models\DynamicRecord;
use App\Plugins\Api\Saft;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Saft\LagerTafel\LagerTafelPlugin;
use Saft\LagerTafel\Warehouse;

/**
 * A.1 – Eigene Tabellensicht „Lagerspiegel“: je Artikel eine Zeile, je Lagerort eine Spalte mit dem Bestand, dazu
 * Gesamtbestand und Mindestbestand. Die Spalten der Lagerorte entstehen zur Laufzeit aus der Tabelle Lagerorte.
 */
final class Lagerspiegel extends Page implements HasTable
{
    use InteractsWithTable;

    public const string ROUTE = 'filament.admin.pages.lager-tafel.lagerspiegel';

    private const string T = 'lager-tafel::tafel.matrix.';

    protected static ?string $slug = 'lager-tafel/lagerspiegel';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<int, array<int, int>>|null Bestände je Artikel und Lagerort, einmal je Anfrage geladen */
    private ?array $matrix = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(LagerTafelPlugin::ID.'.view') ?? false;
    }

    public function getTitle(): string
    {
        return __(self::T.'title');
    }

    private function warehouse(): Warehouse
    {
        return app(Warehouse::class);
    }

    /** @return array<int, int> Lagerort-id => Menge für einen Artikel */
    private function stocks(DynamicRecord $article): array
    {
        $this->matrix ??= $this->warehouse()->matrix();

        return $this->matrix[(int) $article->getKey()] ?? [];
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->warehouse()->available()) {
            return $schema->components([Callout::make(__('lager-tafel::tafel.unavailable_title'))
                ->description(__('lager-tafel::tafel.unavailable').' '.implode('; ', $this->warehouse()->missing()))
                ->warning()]);
        }

        if (! $this->warehouse()->canRead()) {
            return $schema->components([Callout::make(__('lager-tafel::tafel.no_rights'))->danger()]);
        }

        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $warehouse = $this->warehouse();

        // Seite ohne Lager-Demo oder ohne Datenrechte: content() zeigt einen Hinweis, die Tabelle bleibt leer.
        if (! $warehouse->available() || ! $warehouse->canRead()) {
            return $table->records(static fn (): array => []);
        }

        $locationColumns = [];

        foreach ($warehouse->locations() as $id => $label) {
            $locationColumns[] = TextColumn::make('lagerort_'.$id)
                ->label($label)
                ->state(fn (DynamicRecord $record): int => $this->stocks($record)[$id] ?? 0)
                ->numeric()
                ->alignEnd()
                ->color(static fn (int $state): ?string => $state === 0 ? 'gray' : null);
        }

        $hasMinimum = $warehouse->hasMinimum();

        return $table
            // Die Abfrage kommt von der Schnittstelle: ohne Papierkorb, nur mit Leserecht auf Artikel.
            ->query(static fn (): Builder => Saft::table(Warehouse::ARTICLES)->query())
            ->defaultSort('artikelnummer')
            ->columns([
                TextColumn::make('artikelnummer')
                    ->label(__(self::T.'number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bezeichnung')
                    ->label(__(self::T.'article'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                ...$locationColumns,
                TextColumn::make('gesamt')
                    ->label(__(self::T.'total'))
                    ->state(fn (DynamicRecord $record): int => array_sum($this->stocks($record)))
                    ->numeric()
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn (DynamicRecord $record, int $state): ?string => $hasMinimum && $state < (int) $record->getAttribute('mindestbestand') ? 'danger' : null),
                TextColumn::make('mindestbestand')
                    ->label(__(self::T.'minimum'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable()
                    ->visible($hasMinimum),
            ])
            ->filters([
                Filter::make('unter_mindestbestand')
                    ->label(__(self::T.'below_minimum'))
                    ->toggle()
                    ->visible($hasMinimum)
                    ->query(fn (Builder $query): Builder => $query->whereKey($this->warehouse()->belowMinimum())),
            ])
            ->recordActions([
                Action::make('verteilen')
                    ->label(__('lager-tafel::tafel.actions.board'))
                    ->icon('heroicon-o-view-columns')
                    ->iconButton()
                    ->visible(static fn (): bool => Lagertafel::canAccess())
                    ->url(static fn (DynamicRecord $record): string => Lagertafel::getUrl(['artikel' => $record->getKey()])),
            ])
            ->emptyStateHeading(__(self::T.'empty'));
    }
}
