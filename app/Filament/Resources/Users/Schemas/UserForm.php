<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Ai\AiConfig;
use App\Auth\AuthConfiguration;
use App\Models\Role;
use App\Models\User;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('users.form.master_data'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('common.name'))
                            ->required()
                            ->maxLength(255),
                        // Unique inkl. gelöschter User, da der DB-Index sie ebenfalls umfasst.
                        TextInput::make('email')
                            ->label(__('common.email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            // Gespeichert wird normalisiert, also auch so auf Dubletten prüfen.
                            ->mutateStateForValidationUsing(fn (?string $state): ?string => $state === null ? null : User::normalizeEmail($state))
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label(__('users.form.password'))
                            ->password()
                            ->revealable()
                            // Sonst trägt der Browser das gespeicherte Anmeldepasswort ein (nur hier, nicht in der
                            // Bestätigung) – und Speichern scheitert an „stimmt nicht überein“.
                            ->autocomplete('new-password')
                            ->rule(Password::defaults())
                            // Im reinen SSO-Modus optional: Benutzer ohne Passwort melden sich nur per SSO an.
                            ->required(fn (string $operation): bool => $operation === 'create' && app(AuthConfiguration::class)->allowsLocalLogin())
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->confirmed()
                            ->helperText(fn (string $operation): ?string => match (true) {
                                $operation === 'edit' => __('users.form.password_keep'),
                                ! app(AuthConfiguration::class)->allowsLocalLogin() => __('users.form.password_sso'),
                                default => null,
                            }),
                        TextInput::make('password_confirmation')
                            ->label(__('users.form.password_confirmation'))
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->requiredWith('password')
                            ->dehydrated(false),
                        Toggle::make('is_active')
                            ->label(__('common.active'))
                            ->default(true)
                            ->helperText(__('users.form.inactive_help'))
                            // Selbst-Aussperren verhindern.
                            ->disabled(fn (?User $record): bool => $record !== null && $record->is(auth()->user())),
                    ]),
                Section::make(__('common.permissions'))
                    ->schema([
                        Select::make('roles')
                            ->label(__('common.roles'))
                            ->relationship(
                                'roles',
                                'name',
                                modifyQueryUsing: fn (Builder $query): Builder => self::currentUserIsSuperAdmin()
                                    ? $query
                                    : $query->where('name', '!=', User::ROLE_SUPER_ADMIN),
                            )
                            ->multiple()
                            ->preload()
                            ->searchable()
                            // Konfigurierte Standardrolle beim Anlegen vorauswählen.
                            ->default(fn (): array => array_filter([app(AuthConfiguration::class)->defaultRole()?->getKey()]))
                            ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                if (self::currentUserIsSuperAdmin()) {
                                    return;
                                }

                                $superAdminId = Role::findByName(User::ROLE_SUPER_ADMIN, 'web')->getKey();

                                if (in_array($superAdminId, array_map('intval', (array) $value), true)) {
                                    $fail(__('users.form.super_admin_only'));
                                }
                            }),
                        // Nur wenn ein KI-Anbieter eingerichtet ist; leer = Standard, 0 = gesperrt.
                        TextInput::make('ai_daily_limit')
                            ->label(__('assistant.user_limit'))
                            ->helperText(fn (): string => __('assistant.user_limit_help', ['default' => app(AiConfig::class)->defaultDailyLimit()]))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10000)
                            ->visible(fn (): bool => app(AiConfig::class)->configured()),
                    ]),
            ]);
    }

    private static function currentUserIsSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }
}
