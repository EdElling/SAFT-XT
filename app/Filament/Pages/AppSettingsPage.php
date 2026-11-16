<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Ai\AiConfig;
use App\Ai\AiConnectionTester;
use App\Filament\Concerns\LimitsFormWidth;
use App\Settings\AppSettings;
use App\Support\ColorScheme;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

/**
 * Allgemeine Einstellungen der Anwendung. Sammelort für Einstellungen ohne eigenen
 * fachlichen Dialog – weitere Abschnitte kommen hier dazu.
 */
class AppSettingsPage extends SettingsPage
{
    use LimitsFormWidth;

    protected static string $settings = AppSettings::class;

    protected static ?string $slug = 'app-settings';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.app.view') ?? false;
    }

    public function canEdit(): bool
    {
        return auth()->user()?->can('settings.app.update') ?? false;
    }

    public function getTitle(): string
    {
        return __('settings.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->disabled(fn (): bool => ! $this->canEdit())
            ->components([
                Section::make(__('settings.branding'))
                    ->schema([
                        TextInput::make('app_name')
                            ->label(__('settings.app_name'))
                            ->helperText(__('settings.app_name_help'))
                            ->required()
                            ->maxLength(60),
                    ]),
                Section::make(__('settings.appearance'))
                    ->schema([
                        Radio::make('color_scheme')
                            ->label(__('settings.color_scheme'))
                            ->helperText(__('settings.color_scheme_help'))
                            ->options(ColorScheme::options())
                            ->descriptions(array_map(self::swatches(...), array_combine(array_column(ColorScheme::cases(), 'value'), ColorScheme::cases())))
                            ->in(array_column(ColorScheme::cases(), 'value'))
                            ->required(),
                    ]),
                Section::make(__('assistant.settings.section'))
                    ->description(__('assistant.settings.description'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Text::make(fn (): string => $this->aiStatus())->columnSpanFull(),
                        Toggle::make('ai_assistant_enabled')
                            ->label(__('assistant.settings.enabled'))
                            ->helperText(__('assistant.settings.enabled_help'))
                            ->disabled(fn (Get $get): bool => ! app(AiConfig::class)->configured() && ! in_array($get('ai_provider'), AiConfig::PROVIDERS, true)),
                        Toggle::make('ai_send_choices')
                            ->label(__('assistant.settings.send_choices'))
                            ->helperText(__('assistant.settings.send_choices_help')),
                        Toggle::make('ai_help_enabled')
                            ->label(__('assistant.settings.help_enabled'))
                            ->helperText(__('assistant.settings.help_enabled_help'))
                            ->disabled(fn (Get $get): bool => ! app(AiConfig::class)->configured() && ! in_array($get('ai_provider'), AiConfig::PROVIDERS, true)),
                        Toggle::make('ai_help_schema')
                            ->label(__('assistant.settings.help_schema'))
                            ->helperText(__('assistant.settings.help_schema_help')),
                        Select::make('ai_provider')
                            ->label(__('assistant.settings.provider'))
                            ->options(['anthropic' => __('assistant.providers.anthropic'), 'bedrock' => __('assistant.providers.bedrock'), 'vertex' => __('assistant.providers.vertex'), 'none' => __('assistant.settings.provider_off')])
                            ->placeholder(__('assistant.settings.from_env', ['value' => __('assistant.providers.'.(in_array(config('ai.provider'), AiConfig::PROVIDERS, true) ? config('ai.provider') : 'none'))]))
                            ->live(),
                        TextInput::make('ai_model')
                            ->label(__('assistant.settings.model'))
                            ->placeholder((string) config('ai.model'))
                            ->datalist(fn (): array => (array) Cache::get(self::MODELS_CACHE, []))
                            ->maxLength(100),
                        Select::make('ai_effort')
                            ->label(__('assistant.settings.effort'))
                            ->helperText(__('assistant.settings.effort_help'))
                            ->options(collect(AiConfig::EFFORTS)->mapWithKeys(fn (string $e): array => [$e => __('assistant.settings.efforts.'.$e)])->all())
                            ->placeholder(__('assistant.settings.efforts.'.config('ai.effort', 'medium'))),
                        TextInput::make('ai_daily_limit')
                            ->label(__('assistant.settings.daily_limit'))
                            ->helperText(__('assistant.settings.daily_limit_help'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10000)
                            ->placeholder((string) config('ai.daily_limit', 50)),
                        TextInput::make('ai_api_key_new')
                            ->label(__('assistant.settings.api_key'))
                            ->password()
                            ->autocomplete('new-password')
                            ->placeholder(fn (): ?string => app(AiConfig::class)->hasStoredApiKey() ? __('assistant.settings.api_key_stored') : (filled(config('ai.anthropic.api_key')) ? __('assistant.settings.api_key_env') : null))
                            ->helperText(__('assistant.settings.api_key_help'))
                            ->visible(fn (Get $get): bool => $this->formProvider($get) === 'anthropic'),
                        Toggle::make('ai_api_key_remove')
                            ->label(__('assistant.settings.api_key_remove'))
                            ->visible(fn (Get $get): bool => $this->formProvider($get) === 'anthropic' && app(AiConfig::class)->hasStoredApiKey()),
                        TextInput::make('ai_inference_geo')
                            ->label(__('assistant.settings.inference_geo'))
                            ->helperText(__('assistant.settings.inference_geo_help'))
                            ->maxLength(20)
                            ->visible(fn (Get $get): bool => $this->formProvider($get) === 'anthropic'),
                        TextInput::make('ai_bedrock_region')
                            ->label(__('assistant.settings.bedrock_region'))
                            ->placeholder('eu-central-1')
                            ->maxLength(40)
                            ->visible(fn (Get $get): bool => $this->formProvider($get) === 'bedrock'),
                        TextInput::make('ai_vertex_project')
                            ->label(__('assistant.settings.vertex_project'))
                            ->maxLength(100)
                            ->visible(fn (Get $get): bool => $this->formProvider($get) === 'vertex'),
                        TextInput::make('ai_vertex_location')
                            ->label(__('assistant.settings.vertex_location'))
                            ->placeholder('europe-west1')
                            ->maxLength(40)
                            ->visible(fn (Get $get): bool => $this->formProvider($get) === 'vertex'),
                        Actions::make([
                            Action::make('testAi')
                                ->label(__('assistant.settings.test.button'))
                                ->icon('heroicon-o-signal')
                                ->color('gray')
                                ->action(fn () => $this->testAi()),
                        ])->columnSpanFull(),
                    ]),
            ]);
    }

    private const string MODELS_CACHE = 'ai:models';

    /** Anbieter laut Formular (leer = aus der .env). */
    private function formProvider(Get $get): string
    {
        return filled($get('ai_provider')) ? (string) $get('ai_provider') : (string) config('ai.provider');
    }

    private function aiStatus(): string
    {
        $config = app(AiConfig::class);

        return $config->configured()
            ? __('assistant.settings.status', ['provider' => $config->providerLabel(), 'model' => $config->model()]).' · '.__('assistant.settings.source.'.$config->source())
            : __('assistant.settings.not_configured');
    }

    /** Verbindung mit den Werten im Formular prüfen (noch nicht gespeichert). */
    public function testAi(): void
    {
        abort_unless($this->canEdit(), 403);
        $settings = clone app(AppSettings::class);

        foreach (['ai_provider', 'ai_model', 'ai_effort', 'ai_inference_geo', 'ai_bedrock_region', 'ai_vertex_project', 'ai_vertex_location'] as $property) {
            $settings->{$property} = filled($this->data[$property] ?? null) ? (string) $this->data[$property] : null;
        }

        $key = filled($this->data['ai_api_key_new'] ?? null) ? (string) $this->data['ai_api_key_new'] : null;
        $result = app(AiConnectionTester::class)->test(new AiConfig($settings), $key);

        if ($result['models'] !== []) {
            Cache::put(self::MODELS_CACHE, $result['models'], now()->addDay());
        }

        Notification::make()->title($result['message'])->status($result['ok'] ? 'success' : 'danger')->send();
    }

    /** Der gespeicherte API-Schlüssel gelangt nie ins Formular. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['ai_api_key']);

        return [...$data, 'ai_api_key_new' => null, 'ai_api_key_remove' => false];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $stored = app(AppSettings::class)->ai_api_key;
        $data['ai_api_key'] = filled($data['ai_api_key_new'] ?? null)
            ? trim((string) $data['ai_api_key_new'])
            : (($data['ai_api_key_remove'] ?? false) ? null : $stored);
        unset($data['ai_api_key_new'], $data['ai_api_key_remove']);

        foreach (['ai_provider', 'ai_model', 'ai_effort', 'ai_inference_geo', 'ai_bedrock_region', 'ai_vertex_project', 'ai_vertex_location'] as $property) {
            $data[$property] = filled($data[$property] ?? null) ? trim((string) $data[$property]) : null;
        }

        $data['ai_daily_limit'] = filled($data['ai_daily_limit'] ?? null) ? (int) $data['ai_daily_limit'] : null;

        return $data;
    }

    /** Farbmuster: Schaltfläche und Markierung der ausgewählten Zeile. */
    private static function swatches(ColorScheme $scheme): HtmlString
    {
        $box = static fn (string $color, string $title): string => '<span title="'.e($title).'" style="display:inline-block;width:2.25rem;height:1rem;border-radius:.25rem;margin-inline-end:.375rem;vertical-align:middle;background:'.e($color).'"></span>';

        return new HtmlString($box($scheme->swatch(), __('settings.swatch_button')).$box($scheme->highlight() ?? $scheme->swatch(), __('settings.swatch_highlight')));
    }

    protected function afterSave(): void
    {
        // Sofort wirksam, nicht erst beim nächsten Request.
        config(['app.name' => app(AppSettings::class)->app_name, 'mail.from.name' => app(AppSettings::class)->app_name]);

        // Vollständig neu laden (kein SPA-Wechsel), damit Kopfzeile, Titel und Farben überall gelten.
        $this->redirect(static::getUrl(), navigate: false);
    }

    public function getRedirectUrl(): ?string
    {
        // Siehe afterSave(): vollständig neu laden statt SPA-Wechsel.
        return null;
    }
}
