<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Apps\AppPermissions;
use App\Documents\Letterhead;
use App\Documents\LetterheadInspector;
use App\Documents\LetterheadResolver;
use App\Documents\PdfFonts;
use App\Documents\PdfRenderer;
use App\Documents\QrBill\QrBillFactory;
use App\Documents\SvgSanitizer;
use App\Filament\Concerns\LimitsFormWidth;
use App\Models\App;
use App\Models\DocumentLetterhead;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Administration › Anwendung › Briefpapier (B-124, D1a): Briefpapier der Installation und je App die Abweichungen.
 * Installation: Recht „Globale Einstellungen: bearbeiten“; App: App-Recht documents.manage. „Probeseite“ erzeugt ein
 * PDF mit Blindtext aus dem gespeicherten Stand.
 */
class DocumentSettingsPage extends Page
{
    use LimitsFormWidth;

    public const string INSTALLATION = 'installation';

    protected static ?string $slug = 'document-settings';

    protected static bool $shouldRegisterNavigation = false;

    /** Gewählte Ebene: „installation“ oder die id einer App. */
    public string $scope = self::INSTALLATION;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && self::scopes($user) !== [];
    }

    /** @return array<string, string> Ebenen, die die Person bearbeiten darf */
    public static function scopes(User $user): array
    {
        $scopes = $user->can('settings.app.update') ? [self::INSTALLATION => __('documents.settings.installation')] : [];

        foreach (App::query()->orderBy('sort_order')->orderBy('id')->get() as $app) {
            if (AppPermissions::can($user, 'documents.manage', $app->id)) {
                $scopes[(string) $app->id] = __('documents.settings.app', ['app' => $app->name]);
            }
        }

        return $scopes;
    }

    public function getTitle(): string
    {
        return __('documents.settings.title');
    }

    public function mount(): void
    {
        $scopes = self::scopes($this->user());
        $requested = (string) request()->query('scope', '');
        $this->scope = array_key_exists($requested, $scopes) ? $requested : (string) array_key_first($scopes);
        $this->load();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function appId(): ?int
    {
        return $this->scope === self::INSTALLATION ? null : (int) $this->scope;
    }

    private function row(): ?DocumentLetterhead
    {
        return $this->appId() === null ? DocumentLetterhead::installation() : DocumentLetterhead::query()->where('app_id', $this->appId())->first();
    }

    private function installationRow(): ?DocumentLetterhead
    {
        return DocumentLetterhead::query()->whereNull('app_id')->first();
    }

    public function updatedScope(): void
    {
        abort_unless(array_key_exists($this->scope, self::scopes($this->user())), 403);
        $this->load();
    }

    private function load(): void
    {
        $row = $this->row();
        $state = $row?->only(['first_page_path', 'next_pages_path', 'logo_path', 'logo_height', 'font_regular_path', 'font_bold_path', 'font_italic_path',
            'color_primary', 'color_secondary', 'color_text', 'address_position', 'sender_line']) ?? [];
        $state['sender'] = Arr::only((array) ($row?->sender ?? []), LetterheadResolver::SENDER_KEYS);
        $state['margins'] = (array) ($row?->margins ?? []);
        $state['pdfa'] = $row?->pdfa ?? true;
        $state['pdfa_app'] = match ($row?->pdfa) {
            true => 'on', false => 'off', default => 'inherit',
        };
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $inherit = fn (): bool => $this->appId() !== null;
        $base = fn (): Letterhead => app(LetterheadResolver::class)->combine(null, $this->installationRow());
        $pdf = fn (string $name): FileUpload => FileUpload::make($name)
            ->disk('local')->directory(DocumentLetterhead::DIRECTORY)->visibility('private')
            ->acceptedFileTypes(['application/pdf'])->maxSize(5120)
            ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                if ($value instanceof TemporaryUploadedFile && ! app(LetterheadInspector::class)->inspect($value->getRealPath())['readable']) {
                    $fail(__('documents.settings.pdf_unreadable'));
                }
            });
        $font = fn (string $name): FileUpload => FileUpload::make($name)
            ->disk('local')->directory(DocumentLetterhead::FONT_DIRECTORY)->visibility('private')
            ->acceptedFileTypes(['font/ttf', 'font/sfnt', 'application/x-font-ttf', 'application/font-sfnt', 'application/x-font-truetype', 'application/octet-stream'])
            ->maxSize(5120)
            ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                if ($value instanceof TemporaryUploadedFile && (! str_ends_with(strtolower($value->getClientOriginalName()), '.ttf') || ! PdfFonts::isConvertible($value->getRealPath()))) {
                    $fail(__('documents.settings.font_ttf'));
                }
            });
        $margin = fn (string $page, string $side): TextInput => TextInput::make("margins.{$page}.{$side}")
            ->label(__('documents.settings.sides.'.$side))->numeric()->minValue(0)->maxValue(150)->suffix('mm')
            ->placeholder(fn (): string => $inherit() ? (string) $base()->margins[$page][$side] : (string) Letterhead::DEFAULT_MARGINS[$page][$side]);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('documents.settings.letterhead'))
                    ->description(__('documents.settings.letterhead_help'))
                    ->columns(2)
                    ->schema([
                        $pdf('first_page_path')->label(__('documents.settings.first_page'))->helperText(__('documents.settings.first_page_help')),
                        $pdf('next_pages_path')->label(__('documents.settings.next_pages'))->helperText(__('documents.settings.next_pages_help')),
                        FileUpload::make('logo_path')->label(__('documents.settings.logo'))->helperText(__('documents.settings.logo_help'))
                            ->disk('local')->directory(DocumentLetterhead::DIRECTORY)->visibility('private')
                            ->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml'])->maxSize(1024)->imagePreviewHeight('60')
                            ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                if ($value instanceof TemporaryUploadedFile && strtolower($value->getClientOriginalExtension()) === 'svg'
                                    && SvgSanitizer::sanitize((string) file_get_contents($value->getRealPath())) === null) {
                                    $fail(__('documents.settings.logo_svg_invalid'));
                                }
                            }),
                        TextInput::make('logo_height')->label(__('documents.settings.logo_height'))
                            ->helperText(__('documents.settings.logo_height_help', ['max' => (int) Letterhead::LOGO_MAX_WIDTH]))
                            ->numeric()->minValue(Letterhead::LOGO_MIN_HEIGHT)->maxValue(Letterhead::LOGO_MAX_HEIGHT)->step(0.5)->suffix('mm')
                            ->placeholder(fn (): string => (string) ($inherit() ? $base()->logoHeight : Letterhead::DEFAULT_LOGO_HEIGHT)),
                    ]),
                Section::make(__('documents.settings.fonts_colors'))
                    ->description(__('documents.settings.fonts_help'))
                    ->columns(3)
                    ->schema([
                        $font('font_regular_path')->label(__('documents.settings.font_regular')),
                        $font('font_bold_path')->label(__('documents.settings.font_bold')),
                        $font('font_italic_path')->label(__('documents.settings.font_italic')),
                        ColorPicker::make('color_primary')->label(__('documents.settings.color_primary'))->regex('/^#[0-9a-fA-F]{6}$/')
                            ->placeholder(fn (): string => $inherit() ? $base()->primary : Letterhead::DEFAULT_PRIMARY),
                        ColorPicker::make('color_secondary')->label(__('documents.settings.color_secondary'))->regex('/^#[0-9a-fA-F]{6}$/')
                            ->placeholder(fn (): string => $inherit() ? $base()->secondary : Letterhead::DEFAULT_SECONDARY),
                        ColorPicker::make('color_text')->label(__('documents.settings.color_text'))->regex('/^#[0-9a-fA-F]{6}$/')
                            ->placeholder(fn (): string => $inherit() ? $base()->text : Letterhead::DEFAULT_TEXT),
                    ]),
                Section::make(__('documents.settings.layout'))
                    ->description(__('documents.settings.layout_help'))
                    ->schema([
                        Grid::make(4)->schema([
                            Text::make(__('documents.settings.margins_first'))->columnSpanFull(),
                            ...array_map(fn (string $side): TextInput => $margin('first', $side), LetterheadResolver::SIDES),
                            Text::make(__('documents.settings.margins_next'))->columnSpanFull(),
                            ...array_map(fn (string $side): TextInput => $margin('next', $side), LetterheadResolver::SIDES),
                        ]),
                        Radio::make('address_position')
                            ->label(__('documents.settings.address_position'))
                            ->options([
                                DocumentLetterhead::LEFT => __('documents.settings.address_left'),
                                DocumentLetterhead::RIGHT => __('documents.settings.address_right'),
                            ])
                            ->helperText(fn (): ?string => $inherit() ? __('documents.settings.inherit_hint') : null),
                        TextInput::make('sender_line')->label(__('documents.settings.sender_line'))->maxLength(200)
                            ->helperText(__('documents.settings.sender_line_help'))
                            ->placeholder(fn (): ?string => $inherit() ? $base()->senderLine : null),
                    ]),
                Section::make(__('documents.settings.sender'))
                    ->description(__('documents.settings.sender_help'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('sender.firma')->label(__('documents.settings.sender_fields.firma'))->maxLength(200)
                            ->placeholder(fn (): ?string => $inherit() ? $base()->sender['firma'] : null),
                        Textarea::make('sender.adresse')->label(__('documents.settings.sender_fields.adresse'))->rows(3)->maxLength(500)
                            ->placeholder(fn (): ?string => $inherit() ? $base()->sender['adresse'] : null),
                        TextInput::make('sender.mwst')->label(__('documents.settings.sender_fields.mwst'))->maxLength(60)
                            ->placeholder(fn (): ?string => $inherit() ? $base()->sender['mwst'] : null),
                        TextInput::make('sender.iban')->label(__('documents.settings.sender_fields.iban'))->maxLength(60)
                            ->rule(static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                                if (filled($value) && ! QrBillFactory::ibanValid((string) $value)) {
                                    $fail(__('documents.settings.iban_invalid'));
                                }
                            })
                            ->placeholder(fn (): ?string => $inherit() ? $base()->sender['iban'] : null),
                        Textarea::make('sender.kontakt')->label(__('documents.settings.sender_fields.kontakt'))->rows(2)->maxLength(500)
                            ->placeholder(fn (): ?string => $inherit() ? $base()->sender['kontakt'] : null),
                        // Zahlungsempfänger der QR-Rechnung (D4a): Adresse in Teilen, wie es der Standard verlangt.
                        Fieldset::make(__('documents.settings.qr_creditor'))->columnSpanFull()->columns(2)->schema([
                            Text::make(__('documents.settings.qr_creditor_help'))->columnSpanFull(),
                            TextInput::make('sender.strasse')->label(__('documents.settings.sender_fields.strasse'))->maxLength(70)
                                ->placeholder(fn (): ?string => $inherit() ? $base()->sender['strasse'] : null),
                            TextInput::make('sender.hausnummer')->label(__('documents.settings.sender_fields.hausnummer'))->maxLength(16)
                                ->placeholder(fn (): ?string => $inherit() ? $base()->sender['hausnummer'] : null),
                            TextInput::make('sender.plz')->label(__('documents.settings.sender_fields.plz'))->maxLength(16)
                                ->placeholder(fn (): ?string => $inherit() ? $base()->sender['plz'] : null),
                            TextInput::make('sender.ort')->label(__('documents.settings.sender_fields.ort'))->maxLength(35)
                                ->placeholder(fn (): ?string => $inherit() ? $base()->sender['ort'] : null),
                            TextInput::make('sender.land')->label(__('documents.settings.sender_fields.land'))->maxLength(2)
                                ->placeholder(fn (): string => $inherit() ? (string) ($base()->sender['land'] ?? 'CH') : 'CH')
                                ->helperText(__('documents.settings.land_help')),
                            TextInput::make('sender.qr_iban')->label(__('documents.settings.sender_fields.qr_iban'))->maxLength(40)
                                ->rule(static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                                    if (filled($value) && ! QrBillFactory::isQrIban((string) $value)) {
                                        $fail(QrBillFactory::ibanValid((string) $value) ? __('documents.settings.qr_iban_invalid') : __('documents.settings.iban_invalid'));
                                    }
                                })
                                ->placeholder(fn (): ?string => $inherit() ? $base()->sender['qr_iban'] : null)
                                ->helperText(__('documents.settings.qr_iban_help')),
                        ]),
                    ]),
                Section::make(__('documents.settings.archive'))
                    ->schema([
                        Toggle::make('pdfa')->label(__('documents.settings.pdfa'))->helperText(__('documents.settings.pdfa_help'))
                            ->visible(fn (): bool => ! $inherit()),
                        Radio::make('pdfa_app')->label(__('documents.settings.pdfa'))->helperText(__('documents.settings.pdfa_help'))
                            ->options(['inherit' => __('documents.settings.pdfa_inherit'), 'on' => __('documents.settings.pdfa_on'), 'off' => __('documents.settings.pdfa_off')])
                            ->inline()
                            ->visible($inherit),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('scope')
                ->label(__('documents.settings.scope'))
                ->helperText(fn (): string => $this->appId() === null ? __('documents.settings.scope_installation_help') : __('documents.settings.scope_app_help'))
                ->options(fn (): array => self::scopes($this->user()))
                ->selectablePlaceholder(false)
                ->live(),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sample')->label(__('documents.settings.sample'))->icon('heroicon-o-document-arrow-down')->color('gray')
                ->tooltip(__('documents.settings.sample_help'))
                ->action(fn (): StreamedResponse => $this->sample()),
            Action::make('reset')->label(__('documents.settings.reset'))->icon('heroicon-o-arrow-uturn-left')->color('danger')
                ->requiresConfirmation()->modalDescription(__('documents.settings.reset_confirm'))
                ->visible(fn (): bool => $this->appId() !== null && $this->row() !== null)
                ->action(fn () => $this->resetApp()),
        ];
    }

    private function authorizeScope(): void
    {
        abort_unless(array_key_exists($this->scope, self::scopes($this->user())), 403);
    }

    public function save(): void
    {
        $this->authorizeScope();
        $data = $this->form->getState();
        $row = $this->row() ?? new DocumentLetterhead(['app_id' => $this->appId()]);

        $margins = [];

        foreach (['first', 'next'] as $page) {
            foreach (LetterheadResolver::SIDES as $side) {
                $value = $data['margins'][$page][$side] ?? null;

                if (is_numeric($value)) {
                    $margins[$page][$side] = (float) $value;
                }
            }
        }

        $sender = array_filter(Arr::only((array) ($data['sender'] ?? []), LetterheadResolver::SENDER_KEYS), static fn (mixed $v): bool => filled($v));
        $text = static fn (mixed $v): ?string => filled($v) ? trim((string) $v) : null;

        $row->fill([
            ...array_map(static fn (mixed $v): ?string => filled($v) ? (string) $v : null, Arr::only($data, DocumentLetterhead::FILES)),
            'color_primary' => $text($data['color_primary'] ?? null),
            'color_secondary' => $text($data['color_secondary'] ?? null),
            'color_text' => $text($data['color_text'] ?? null),
            'margins' => $margins === [] ? null : $margins,
            'address_position' => $text($data['address_position'] ?? null),
            'sender_line' => $text($data['sender_line'] ?? null),
            'logo_height' => is_numeric($data['logo_height'] ?? null) ? (float) $data['logo_height'] : null,
            'sender' => $sender === [] ? null : $sender,
            'pdfa' => $this->appId() === null ? (bool) ($data['pdfa'] ?? true) : match ($data['pdfa_app'] ?? 'inherit') {
                'on' => true, 'off' => false, default => null,
            },
        ])->save();
        $this->sanitizeSvgLogo($row);

        Notification::make()->title(__('documents.settings.saved'))->success()->send();
        $this->warnAboutPdfs($row);
    }

    /** Ein SVG-Logo wird bereinigt gespeichert (ohne Skripte und Verweise nach aussen). */
    private function sanitizeSvgLogo(DocumentLetterhead $row): void
    {
        $disk = Storage::disk('local');

        if ($row->logo_path === null || ! PdfRenderer::isSvg($row->logo_path) || ! $disk->exists($row->logo_path)) {
            return;
        }

        $svg = SvgSanitizer::sanitize((string) $disk->get($row->logo_path));

        if ($svg !== null) {
            $disk->put($row->logo_path, $svg);
        }
    }

    /** Hinweise zum Briefpapier-PDF (nicht A4, Transparenz bei PDF/A) und zum Logo (gd fehlt). */
    private function warnAboutPdfs(DocumentLetterhead $row): void
    {
        $letterhead = app(LetterheadResolver::class)->for($this->appId());
        $hints = [];

        foreach (['first_page_path' => $letterhead->firstPage, 'next_pages_path' => $letterhead->nextPages] as $key => $file) {
            if ($file === null) {
                continue;
            }

            $result = app(LetterheadInspector::class)->inspect($file);
            $name = __('documents.settings.'.($key === 'first_page_path' ? 'first_page' : 'next_pages'));

            if (! $result['a4']) {
                $hints[] = __('documents.settings.hint_a4', ['file' => $name]);
            }

            if ($letterhead->pdfa && $result['transparency']) {
                $hints[] = __('documents.settings.hint_transparency', ['file' => $name]);
            }
        }

        if ($letterhead->firstPage === null && $letterhead->logo !== null && PdfRenderer::logoNeedsGd($letterhead->logo)) {
            $hints[] = __('documents.settings.hint_gd');
        }

        if ($hints !== []) {
            Notification::make()->title(__('documents.settings.hints'))->body(implode("\n\n", $hints))->warning()->persistent()->send();
        }
    }

    public function sample(): StreamedResponse
    {
        $this->authorizeScope();
        $letterhead = app(LetterheadResolver::class)->for($this->appId());
        $pdf = app(PdfRenderer::class)->render(view('documents.sample', ['letterhead' => $letterhead])->render(), $letterhead, [
            'title' => __('documents.sample.title'),
            'address' => nl2br(e(__('documents.sample.address'))),
        ]);

        return response()->streamDownload(static function () use ($pdf): void {
            echo $pdf;
        }, __('documents.sample.file').'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function resetApp(): void
    {
        $this->authorizeScope();
        $this->row()?->delete();
        $this->load();
        Notification::make()->title(__('documents.settings.reset_done'))->success()->send();
    }
}
