<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AuthConfiguration;
use App\Auth\AuthMode;
use App\Filament\Concerns\LimitsFormWidth;
use App\Models\Role;
use App\Models\User;
use App\Settings\AuthSettings;
use Closure;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AuthSettingsPage extends SettingsPage
{
    use LimitsFormWidth;

    protected static string $settings = AuthSettings::class;

    protected static ?string $slug = 'auth-settings';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.auth.view') ?? false;
    }

    public function canEdit(): bool
    {
        return auth()->user()?->can('settings.auth.update') ?? false;
    }

    public function getTitle(): string
    {
        return __('auth_admin.settings.title');
    }

    public function form(Schema $schema): Schema
    {
        $config = app(AuthConfiguration::class);

        return $schema
            ->disabled(fn (): bool => ! $this->canEdit())
            ->components([
                Callout::make(__('auth_admin.errors.forced_local'))
                    ->warning()
                    ->visible($config->isForcedLocal())
                    ->columnSpanFull(),
                Section::make(__('auth_admin.settings.mode'))
                    ->schema([
                        Radio::make('mode')
                            ->hiddenLabel()
                            ->options(AuthMode::options())
                            ->descriptions(collect(AuthMode::cases())->mapWithKeys(fn (AuthMode $m): array => [$m->value => $m->description()])->all())
                            ->required()
                            ->live()
                            ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($config): void {
                                if ($value === AuthMode::Sso->value && ! $config->hasVerifiedProvider()) {
                                    $fail(__('auth_admin.errors.sso_requires_verified_provider'));
                                }
                            }),
                    ]),
                Section::make(__('auth_admin.settings.options'))
                    ->schema([
                        Toggle::make('break_glass_enabled')
                            ->label(__('auth_admin.settings.break_glass'))
                            ->helperText(fn (): string => __('auth_admin.settings.break_glass_help', ['url' => filament()->getLoginUrl().'?local=1'])),
                        Select::make('default_role')
                            ->label(__('auth_admin.settings.default_role'))
                            ->helperText(__('auth_admin.settings.default_role_help'))
                            ->options(fn (): array => Role::query()
                                ->where('guard_name', 'web')
                                ->where('name', '!=', User::ROLE_SUPER_ADMIN)
                                ->orderBy('name')
                                ->pluck('name', 'name')
                                ->all())
                            ->placeholder(__('auth_admin.settings.no_default_role')),
                        Toggle::make('password_reset_enabled')
                            ->label(__('auth_admin.settings.password_reset'))
                            ->helperText(__('auth_admin.settings.password_reset_help'))
                            ->visible(fn (Get $get): bool => $get('mode') !== AuthMode::Sso->value),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['mode'] = $data['mode'] instanceof AuthMode ? $data['mode']->value : $data['mode'];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['mode'] = AuthMode::from((string) $data['mode']);
        $data['password_reset_enabled'] ??= false;
        $data['default_role'] = filled($data['default_role'] ?? null) ? (string) $data['default_role'] : null;

        return $data;
    }
}
