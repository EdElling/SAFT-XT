<?php

declare(strict_types=1);

namespace Saft\LagerTafel\Pages;

use App\Plugins\Api\RecordRejected;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Saft\LagerTafel\LagerTafelPlugin;
use Saft\LagerTafel\Warehouse;
use Saft\LagerUmbuchung\StockTransfer;
use Saft\LagerUmbuchung\TransferRejected;

/**
 * A.2 – Eigenes Bearbeiten-Formular „Lagertafel“: Für einen Artikel erscheint je Lagerort eine Karte mit dem Bestand.
 * Wer den Bestand auf eine andere Karte zieht (Drag and Drop mit wire:sort von Livewire), erhält ein Dialogfenster
 * mit der Menge; gebucht wird über den Ablauf des Plugins lager-umbuchung (zwei Lagerbewegungen, eine Transaktion).
 */
final class Lagertafel extends Page
{
    public const string ROUTE = 'filament.admin.pages.lager-tafel.lagertafel';

    private const string T = 'lager-tafel::tafel.board.';

    protected static ?string $slug = 'lager-tafel/lagertafel';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'lager-tafel::lagertafel';

    /** @var array{artikel?: int|string|null} Zustand des Formulars (Auswahl des Artikels) */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(LagerTafelPlugin::ID.'.transfer') ?? false;
    }

    public function getTitle(): string
    {
        return __(self::T.'title');
    }

    /** Vorbelegung aus dem Knopf „Auf Lagertafel verteilen“ (?artikel=…). */
    public function mount(): void
    {
        $this->form->fill(['artikel' => request()->integer('artikel') ?: null]);
    }

    private function warehouse(): Warehouse
    {
        return app(Warehouse::class);
    }

    private function ready(): bool
    {
        return $this->warehouse()->available() && $this->warehouse()->canRead();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components($this->ready() ? [
                Select::make('artikel')
                    ->label(__(self::T.'article'))
                    ->options(fn (): array => $this->warehouse()->articles())
                    ->searchable()
                    ->live(),
            ] : []);
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

        return $schema->components([EmbeddedSchema::make('form')]);
    }

    public function article(): ?int
    {
        $id = (int) ($this->data['artikel'] ?? 0);

        return $id > 0 && $this->ready() ? $id : null;
    }

    /**
     * Karten der Tafel für den gewählten Artikel – alle Lagerorte, auch ohne Bestand (dorthin lässt sich ziehen).
     *
     * @return list<array{id: int, label: string, quantity: int}>
     */
    public function board(): array
    {
        if (($article = $this->article()) === null) {
            return [];
        }

        $stocks = $this->warehouse()->stocksOf($article);
        $cards = [];

        foreach ($this->warehouse()->locations() as $id => $label) {
            $cards[] = ['id' => $id, 'label' => $label, 'quantity' => $stocks[$id] ?? 0];
        }

        return $cards;
    }

    /**
     * Ziel von wire:sort: Livewire ruft die Methode mit dem Schlüssel des gezogenen Elements (wire:sort:item = Quellort),
     * der Position und der Kennung der Zielkarte (wire:sort:group-id = Zielort) auf. Gebucht wird erst im Dialog.
     */
    public function drop(int|string $item, int $position, int|string $target): void
    {
        abort_unless(self::canAccess(), 403);
        [$from, $to] = [(int) $item, (int) $target];

        if ($from === $to || $this->article() === null) {
            return;
        }

        $this->mountAction('transfer', ['from' => $from, 'to' => $to]);
    }

    /** Dialog nach dem Ablegen: Menge wählen (vorbelegt mit dem ganzen Bestand am Quellort) und umbuchen. */
    public function transferAction(): Action
    {
        return Action::make('transfer')
            ->modalHeading(fn (array $arguments): string => __(self::T.'modal', [
                'from' => $this->warehouse()->locations()[(int) $arguments['from']] ?? '?',
                'to' => $this->warehouse()->locations()[(int) $arguments['to']] ?? '?',
            ]))
            ->modalSubmitActionLabel(__(self::T.'submit'))
            ->fillForm(fn (array $arguments): array => ['menge' => $this->available((int) $arguments['from'])])
            ->schema(fn (array $arguments): array => [
                TextInput::make('menge')
                    ->label(__(self::T.'quantity'))
                    ->integer()
                    ->required()
                    ->minValue(1)
                    ->maxValue($this->available((int) $arguments['from']))
                    ->autofocus(),
            ])
            ->action(function (array $data, array $arguments): void {
                abort_unless(self::canAccess(), 403);
                $this->transfer((int) $arguments['from'], (int) $arguments['to'], (int) $data['menge']);
            });
    }

    private function available(int $location): int
    {
        return $this->article() === null ? 0 : ($this->warehouse()->stocksOf($this->article())[$location] ?? 0);
    }

    /** Buchen und das Ergebnis als Meldung zeigen; die Tafel wird danach neu gezeichnet. */
    private function transfer(int $from, int $to, int $quantity): void
    {
        $transfers = app(StockTransfer::class);

        if (($missing = $transfers->missingRights()) !== []) {
            Notification::make()->danger()->title(__(self::T.'rights', ['tables' => implode(', ', $missing)]))->send();

            return;
        }

        try {
            $transfers->transfer((int) $this->article(), $from, $to, $quantity);
        } catch (TransferRejected|RecordRejected $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(__(self::T.'invalid'))->body(implode("\n", Arr::flatten($e->errors())))->send();

            return;
        }

        $locations = $this->warehouse()->locations();
        Notification::make()->success()->title(__(self::T.'done', [
            'count' => $quantity,
            'from' => $locations[$from] ?? '',
            'to' => $locations[$to] ?? '',
        ]))->send();
    }
}
