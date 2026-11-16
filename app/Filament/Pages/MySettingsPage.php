<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\TwoFactor;
use App\Filament\Concerns\LimitsFormWidth;
use App\Filament\Support\PanelAppearance;
use App\Models\User;
use App\Support\AppLocale;
use App\Support\HighContrast;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Persönliche Einstellungen der angemeldeten Person (Benutzermenü). Für alle angemeldeten
 * Personen, ohne eigenes Recht; jede Person ändert nur ihr eigenes Konto.
 */
class MySettingsPage extends Page
{
    use LimitsFormWidth;

    protected static ?string $slug = 'my-settings';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    public function getTitle(): string
    {
        return __('my_settings.title');
    }

    public function mount(): void
    {
        $user = auth()->user();
        $this->form->fill([
            'high_contrast' => PanelAppearance::highContrast()->value,
            'number_format' => $user instanceof User ? ($user->number_format ?? 'auto') : 'auto',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('my_settings.appearance'))
                    ->schema([
                        Radio::make('high_contrast')
                            ->label(__('my_settings.high_contrast'))
                            ->helperText(__('my_settings.high_contrast_help'))
                            ->options(HighContrast::options())
                            ->descriptions([
                                HighContrast::Auto->value => __('my_settings.high_contrast_descriptions.auto'),
                                HighContrast::On->value => __('my_settings.high_contrast_descriptions.on'),
                                HighContrast::Off->value => __('my_settings.high_contrast_descriptions.off'),
                            ])
                            ->in(array_column(HighContrast::cases(), 'value'))
                            ->required(),
                        Radio::make('number_format')
                            ->label(__('my_settings.number_format'))
                            ->helperText(__('my_settings.number_format_help'))
                            ->options([
                                'auto' => __('my_settings.number_format_options.auto'),
                                AppLocale::NUMBERS_BY_LANGUAGE => __('my_settings.number_format_options.lang'),
                                'CH' => __('my_settings.number_format_options.CH'),
                            ])
                            ->descriptions([
                                'auto' => __('my_settings.number_format_descriptions.auto'),
                                AppLocale::NUMBERS_BY_LANGUAGE => __('my_settings.number_format_descriptions.lang'),
                                'CH' => __('my_settings.number_format_descriptions.CH'),
                            ])
                            ->in(['auto', AppLocale::NUMBERS_BY_LANGUAGE, 'CH'])
                            ->required(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
            $this->twoFactorSection(),
        ]);
    }

    /** Zwei-Faktor-Anmeldung einrichten, Codes erneuern, ausschalten (Aktionen von Filament); nicht für reine SSO-Konten. */
    private function twoFactorSection(): Section
    {
        $user = auth()->user();
        $twoFactor = app(TwoFactor::class);

        return Section::make(__('my_settings.two_factor'))
            ->description(fn (): string => match (true) {
                $user instanceof User && $twoFactor->isRequiredFor($user) => __('my_settings.two_factor_required'),
                default => __('my_settings.two_factor_help'),
            })
            ->schema(collect(Filament::getMultiFactorAuthenticationProviders())
                ->map(fn (MultiFactorAuthenticationProvider $provider): Group => Group::make($provider->getManagementSchemaComponents())->statePath($provider->getId()))
                ->values()
                ->all())
            ->visible($user instanceof User && $twoFactor->isAvailableFor($user));
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $user->update([
            'high_contrast' => HighContrast::from((string) $data['high_contrast'])->toStored(),
            'number_format' => $data['number_format'] === 'auto' ? null : (string) $data['number_format'],
        ]);

        Notification::make()->title(__('my_settings.saved'))->success()->send();

        // Vollständig neu laden: Die Kontrastklasse wird beim Laden der Seite gesetzt.
        $this->redirect(static::getUrl(), navigate: false);
    }
}
