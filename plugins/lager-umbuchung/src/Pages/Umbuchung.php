<?php

declare(strict_types=1);

namespace Saft\LagerUmbuchung\Pages;

use App\Filament\Concerns\LimitsFormWidth;
use App\Plugins\Api\Saft;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Saft\LagerUmbuchung\LagerUmbuchungPlugin;
use Saft\LagerUmbuchung\StockTransfer;
use Saft\LagerUmbuchung\TransferRejected;

/** Fachmaske „Umbuchung“: Artikel von einem Lagerort an einen anderen umbuchen. */
final class Umbuchung extends Page
{
    use LimitsFormWidth;

    public const string ROUTE = 'filament.admin.pages.lager-umbuchung';

    private const string T = 'lager-umbuchung::umbuchung.';

    protected static ?string $slug = 'lager-umbuchung';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(LagerUmbuchungPlugin::ID.'.execute') ?? false;
    }

    public function getTitle(): string
    {
        return __(self::T.'title');
    }

    /** Vorbelegung aus der Zeilenaktion „Umbuchen“ der Tabelle Bestände (?artikel=…&von=…). */
    public function mount(): void
    {
        $this->form->fill(array_filter([
            'artikel' => request()->integer('artikel') ?: null,
            'von' => request()->integer('von') ?: null,
        ]));
    }

    private function transfers(): StockTransfer
    {
        return app(StockTransfer::class);
    }

    public function form(Schema $schema): Schema
    {
        if (! $this->transfers()->available()) {
            return $schema->statePath('data')->components([]);
        }

        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description(__(self::T.'help'))
                    ->columns(2)
                    ->schema([
                        Select::make('artikel')
                            ->label(__(self::T.'fields.article'))
                            ->options(fn (): array => $this->transfers()->articles())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('von', null);
                                $set('menge', null);
                            })
                            ->columnSpanFull(),
                        Select::make('von')
                            ->label(__(self::T.'fields.from'))
                            ->options(fn (Get $get): array => $this->sourceOptions((int) $get('artikel')))
                            ->helperText(fn (Get $get): ?string => filled($get('artikel')) && $this->sourceOptions((int) $get('artikel')) === [] ? __(self::T.'no_stock') : null)
                            ->disabled(fn (Get $get): bool => blank($get('artikel')))
                            ->required()
                            ->live(),
                        Select::make('nach')
                            ->label(__(self::T.'fields.to'))
                            ->options(fn (Get $get): array => array_diff_key($this->transfers()->locations(), [(int) $get('von') => true]))
                            ->different('von')
                            ->validationMessages(['different' => __(self::T.'errors.same_location')])
                            ->required(),
                        TextInput::make('menge')
                            ->label(__(self::T.'fields.quantity'))
                            ->integer()
                            ->required()
                            ->minValue(1)
                            ->maxValue(fn (Get $get): ?int => filled($get('artikel')) && filled($get('von')) ? $this->transfers()->stock((int) $get('artikel'), (int) $get('von')) : null)
                            ->helperText(fn (Get $get): ?string => filled($get('artikel')) && filled($get('von'))
                                ? __(self::T.'available', ['count' => $this->transfers()->stock((int) $get('artikel'), (int) $get('von'))]) : null),
                        // Feld aus dem Table-Designer: Bezeichnung und Prüfregeln wie in der Tabelle Lagerbewegungen.
                        ...array_map(
                            static fn ($field) => $field->columnSpanFull(),
                            Saft::table(StockTransfer::MOVEMENTS)->formFields(only: ['bemerkung']),
                        ),
                    ]),
            ]);
    }

    /** @return array<int, string> Lagerorte mit Bestand: id => „Ort (Bestand: n)“ */
    private function sourceOptions(int $article): array
    {
        if ($article === 0) {
            return [];
        }

        $locations = $this->transfers()->locations();
        $options = [];

        foreach ($this->transfers()->stocks($article) as $location => $quantity) {
            $options[$location] = __(self::T.'location_with_stock', ['location' => $locations[$location] ?? $location, 'count' => $quantity]);
        }

        return $options;
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->transfers()->available()) {
            return $schema->components([Callout::make(__(self::T.'unavailable_title'))
                ->description(__(self::T.'unavailable').' '.implode('; ', $this->transfers()->missing()))
                ->warning()]);
        }

        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('transfer')
                ->footer([Actions::make([Action::make('transfer')->label(__(self::T.'submit'))->submit('transfer')])]),
        ]);
    }

    public function transfer(): void
    {
        abort_unless(self::canAccess(), 403);
        $data = $this->form->getState();

        if (($missing = $this->transfers()->missingRights()) !== []) {
            Notification::make()->danger()->title(__(self::T.'errors.rights', ['tables' => implode(', ', $missing)]))->send();

            return;
        }

        try {
            $this->transfers()->transfer((int) $data['artikel'], (int) $data['von'], (int) $data['nach'], (int) $data['menge'], $data['bemerkung'] ?? null);
        } catch (TransferRejected $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            // Regeln des Table-Designers (z. B. Bemerkung zu lang): Meldungen der Schnittstelle gesammelt anzeigen.
            Notification::make()->danger()->title(__(self::T.'errors.invalid'))->body(implode("\n", Arr::flatten($e->errors())))->send();

            return;
        }

        $locations = $this->transfers()->locations();
        Notification::make()->success()->title(__(self::T.'done', [
            'count' => (int) $data['menge'],
            'article' => $this->transfers()->articles()[(int) $data['artikel']] ?? '',
            'from' => $locations[(int) $data['von']] ?? '',
            'to' => $locations[(int) $data['nach']] ?? '',
        ]))->send();

        $this->form->fill();
    }
}
