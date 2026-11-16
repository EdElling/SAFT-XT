<?php

declare(strict_types=1);

namespace App\Filament\Resources\IdentityProviders\Schemas;

use App\Auth\AuthConfiguration;
use App\Auth\Sso\IdentityProviderType;
use App\Auth\Sso\Saml\Certificates;
use App\Auth\Sso\Testing\EntraConnectionTester;
use App\Models\IdentityProvider;
use App\Models\User;
use App\Support\AppLocale;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class IdentityProviderForm
{
    public const string SAML_ATTR_EMAIL = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress';

    public const string SAML_ATTR_NAME = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name';

    /** @var list<string> */
    public const array MULTI_TENANT_ENDPOINTS = ['common', 'organizations', 'consumers'];

    private const string HOST_REGEX = '/^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$/';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                self::generalSection(),
                self::entraSection(),
                self::samlSection(),
                self::provisioningSection(),
                self::roleMappingSection(),
                self::logoutSection(),
                self::testSection(),
            ]);
    }

    /**
     * Übernimmt neu eingegebene Secrets; leere Felder behalten den gespeicherten Wert.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applySecrets(array $data, ?IdentityProvider $record): array
    {
        $secrets = $record?->secrets ?? [];

        foreach ((array) ($data['new_secrets'] ?? []) as $key => $value) {
            if (filled($value)) {
                $secrets[$key] = (string) $value;
            }
        }

        unset($data['new_secrets']);

        // Secrets eines anderen Typs (z. B. nach Typwechsel) nicht mitschleppen.
        $type = IdentityProviderType::tryFrom((string) ($data['type'] ?? $record?->type?->value));
        $data['secrets'] = $type === null ? $secrets : array_intersect_key($secrets, array_flip($type->secretKeys()));

        return $data;
    }

    private static function generalSection(): Section
    {
        return Section::make(__('idp.general'))
            ->columns(2)
            ->schema([
                ToggleButtons::make('type')
                    ->label(__('common.type'))
                    ->options(IdentityProviderType::options())
                    ->inline()
                    ->required()
                    ->live()
                    ->default(IdentityProviderType::Entra->value)
                    ->disabledOn('edit')
                    ->columnSpanFull(),
                TextInput::make('name')
                    ->label(__('tables.form.display_name'))
                    ->helperText(__('idp.name_help'))
                    ->required()
                    ->maxLength(100)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
                        if ($operation === 'create' && blank($get('slug'))) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->label(__('idp.slug'))
                    ->helperText(__('idp.slug_help'))
                    ->required()
                    ->maxLength(100)
                    ->alphaDash()
                    ->live(onBlur: true)
                    ->unique(ignoreRecord: true),
                Toggle::make('is_enabled')
                    ->label(__('idp.enabled'))
                    ->inline(false),
                TextInput::make('sort_order')
                    ->label(__('common.sort_order'))
                    ->integer()
                    ->minValue(0)
                    ->default(0),
            ]);
    }

    private static function entraSection(): Section
    {
        return Section::make('Microsoft Entra ID')
            ->description(__('idp.entra.description'))
            ->visible(fn (Get $get): bool => self::type($get) === IdentityProviderType::Entra)
            ->columns(2)
            ->schema([
                self::copyableUrl(__('idp.entra.redirect_uri'), fn (Get $get): string => route('auth.sso.callback', self::slug($get))),
                TextInput::make('settings.tenant_id')
                    ->label(__('idp.entra.tenant'))
                    ->helperText(__('idp.entra.tenant_help'))
                    ->required()
                    ->regex('/^[A-Za-z0-9.-]+$/')
                    ->maxLength(100)
                    // Mandantenübergreifende Endpunkte würden Anmeldungen aus fremden Tenants
                    // zulassen (Kontoübernahme per E-Mail-Verknüpfung möglich).
                    ->rule(static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                        if (in_array(mb_strtolower(trim((string) $value)), self::MULTI_TENANT_ENDPOINTS, true)) {
                            $fail(__('auth_admin.entra.multi_tenant_not_allowed'));
                        }
                    }),
                TextInput::make('settings.client_id')
                    ->label(__('idp.entra.client'))
                    ->required()
                    ->uuid(),
                TextInput::make('new_secrets.client_secret')
                    ->label(__('idp.entra.secret'))
                    ->password()
                    ->autocomplete('new-password')
                    ->required(fn (?IdentityProvider $record): bool => ! ($record?->hasSecret('client_secret') ?? false))
                    ->placeholder(fn (?IdentityProvider $record): ?string => $record?->hasSecret('client_secret') ? __('idp.secret_stored') : null)
                    ->helperText(__('idp.secret_help'))
                    ->maxLength(500),
                Callout::make(__('idp.entra.permission'))
                    ->description(__('idp.entra.permission_help'))
                    ->info()
                    ->columnSpanFull(),
                TextInput::make('settings.authority_host')
                    ->label('Authority-Host')
                    ->placeholder(EntraConnectionTester::DEFAULT_AUTHORITY)
                    ->helperText(__('idp.entra.authority_help'))
                    ->regex(self::HOST_REGEX)
                    ->maxLength(255),
                TextInput::make('settings.graph_url')
                    ->label(__('idp.entra.graph'))
                    ->placeholder('https://graph.microsoft.com/v1.0/me')
                    ->helperText(__('idp.entra.graph_help'))
                    ->url()
                    ->startsWith(['https://'])
                    ->maxLength(255),
            ]);
    }

    private static function samlSection(): Section
    {
        return Section::make('SAML 2.0')
            ->visible(fn (Get $get): bool => self::type($get) === IdentityProviderType::Saml)
            ->schema([
                Fieldset::make(__('idp.saml.values'))
                    ->columns(1)
                    ->schema([
                        self::copyableUrl(__('idp.saml.sp_metadata'), fn (Get $get): string => route('auth.saml.metadata', self::slug($get))),
                        self::copyableUrl('Assertion Consumer Service (ACS)', fn (Get $get): string => route('auth.saml.acs', self::slug($get))),
                        self::copyableUrl('SP Entity-ID', fn (Get $get): string => filled($get('settings.sp_entity_id'))
                            ? (string) $get('settings.sp_entity_id')
                            : route('auth.saml.metadata', self::slug($get))),
                    ]),
                Fieldset::make(__('idp.saml.idp'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('settings.idp_metadata_url')
                            ->label(__('idp.saml.metadata_url'))
                            ->helperText(__('idp.saml.metadata_url_help'))
                            ->url()
                            ->startsWith(['https://'])
                            ->maxLength(500)
                            ->columnSpanFull(),
                        TextInput::make('settings.idp_entity_id')
                            ->label('IdP Entity-ID')
                            ->requiredWithout('settings.idp_metadata_url')
                            ->maxLength(500),
                        TextInput::make('settings.idp_sso_url')
                            ->label('IdP SSO-URL')
                            ->requiredWithout('settings.idp_metadata_url')
                            ->url()
                            ->startsWith(['https://'])
                            ->maxLength(500),
                        Textarea::make('settings.idp_certificate')
                            ->label(__('idp.saml.idp_certificate'))
                            ->requiredWithout('settings.idp_metadata_url')
                            ->rows(4)
                            ->rule(fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                                if (filled($value) && Certificates::validTo((string) $value) === null) {
                                    $fail(__('auth_admin.test.saml_cert_invalid'));
                                }
                            })
                            ->columnSpanFull(),
                    ]),
                Fieldset::make(__('idp.saml.sp'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('settings.sp_entity_id')
                            ->label(__('idp.saml.sp_entity_id'))
                            ->placeholder(__('idp.saml.sp_entity_id_default'))
                            ->live(onBlur: true)
                            ->maxLength(500),
                        Select::make('settings.name_id_format')
                            ->label('NameID-Format')
                            ->live()
                            ->options([
                                'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent' => 'persistent (empfohlen)',
                                'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress' => 'emailAddress',
                                'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified' => 'unspecified',
                            ])
                            ->default('urn:oasis:names:tc:SAML:2.0:nameid-format:persistent')
                            ->required(),
                        Callout::make(__('auth_admin.saml.name_id_email_warning'))
                            ->warning()
                            ->visible(fn (Get $get): bool => $get('settings.name_id_format') === 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress')
                            ->columnSpanFull(),
                        Toggle::make('settings.sign_requests')
                            ->label(__('idp.saml.sign_requests'))
                            ->default(true)
                            ->inline(false)
                            ->live(),
                        Actions::make([
                            Action::make('generateSpCertificate')
                                ->label(__('idp.saml.generate'))
                                ->icon('heroicon-o-key')
                                ->requiresConfirmation()
                                ->modalDescription(__('idp.saml.generate_help'))
                                ->action(function (Set $set): void {
                                    $pair = Certificates::generateSelfSigned((string) parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'saml-sp');
                                    $set('settings.sp_certificate', $pair['certificate']);
                                    $set('new_secrets.sp_private_key', $pair['private_key']);

                                    Notification::make()->title(__('idp.saml.generated'))->success()->send();
                                }),
                        ])->visible(fn (Get $get): bool => (bool) $get('settings.sign_requests'))
                            ->verticallyAlignEnd(),
                        Textarea::make('settings.sp_certificate')
                            ->label(__('idp.saml.sp_certificate'))
                            ->rows(4)
                            ->required(fn (Get $get): bool => (bool) $get('settings.sign_requests'))
                            ->visible(fn (Get $get): bool => (bool) $get('settings.sign_requests'))
                            ->columnSpanFull(),
                        Textarea::make('new_secrets.sp_private_key')
                            ->label(__('idp.saml.sp_private_key'))
                            ->rows(4)
                            ->placeholder(fn (?IdentityProvider $record): ?string => $record?->hasSecret('sp_private_key') ? __('idp.secret_stored') : null)
                            ->required(fn (Get $get, ?IdentityProvider $record): bool => (bool) $get('settings.sign_requests') && ! ($record?->hasSecret('sp_private_key') ?? false))
                            ->visible(fn (Get $get): bool => (bool) $get('settings.sign_requests'))
                            ->helperText(__('idp.secret_help'))
                            ->columnSpanFull(),
                    ]),
                Fieldset::make(__('idp.saml.attributes'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('settings.attribute_email')
                            ->label(__('idp.saml.attribute_email'))
                            ->default(self::SAML_ATTR_EMAIL)
                            ->required()
                            ->maxLength(255),
                        TextInput::make('settings.attribute_name')
                            ->label(__('idp.saml.attribute_name'))
                            ->default(self::SAML_ATTR_NAME)
                            ->maxLength(255),
                    ]),
            ]);
    }

    private static function provisioningSection(): Section
    {
        return Section::make(__('idp.users.title'))
            ->columns(2)
            ->schema([
                Toggle::make('link_by_email')
                    ->label(__('idp.users.link_by_email'))
                    ->helperText(__('idp.users.link_by_email_help'))
                    ->default(true),
                Toggle::make('auto_provision')
                    ->label(__('idp.users.auto_provision'))
                    ->live(),
                Select::make('default_role_id')
                    ->label(__('auth_admin.settings.default_role'))
                    ->relationship(
                        'role',
                        'name',
                        // Super-Admin darf nie automatisch vergeben werden.
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('name', '!=', User::ROLE_SUPER_ADMIN),
                    )
                    // Leer heißt nicht „keine Rolle“, sondern „globale Standardrolle“ – das zeigt der Platzhalter.
                    ->placeholder(fn (): string => ($global = app(AuthConfiguration::class)->defaultRole()) !== null
                        ? __('idp.users.default_role_global', ['role' => $global->name])
                        : __('idp.users.default_role_none'))
                    ->helperText(__('idp.users.default_role_help'))
                    ->visible(fn (Get $get): bool => (bool) $get('auto_provision'))
                    ->preload(),
                TagsInput::make('allowed_domains')
                    ->label(__('idp.users.domains'))
                    ->placeholder(__('idp.users.domains_placeholder'))
                    ->helperText(__('idp.users.domains_help'))
                    ->nestedRecursiveRules(['regex:'.self::HOST_REGEX])
                    ->columnSpanFull(),
            ]);
    }

    private static function roleMappingSection(): Section
    {
        return Section::make(__('idp.roles.title'))
            ->description(__('idp.roles.description'))
            ->columns(2)
            ->schema([
                Toggle::make('settings.role_sync')
                    ->label(__('idp.roles.sync'))
                    ->default(false)
                    ->live()
                    ->columnSpanFull(),
                Select::make('settings.role_claim')
                    ->label(__('idp.roles.claim'))
                    ->options([
                        'roles' => __('idp.roles.claim_roles'),
                        'groups' => __('idp.roles.claim_groups'),
                    ])
                    ->default('roles')
                    ->required(fn (Get $get): bool => (bool) $get('settings.role_sync'))
                    ->live()
                    ->visible(fn (Get $get): bool => (bool) $get('settings.role_sync') && self::type($get) === IdentityProviderType::Entra),
                Callout::make(fn (Get $get): string => $get('settings.role_claim') === 'groups'
                    ? __('idp.roles.groups_help')
                    : __('idp.roles.roles_help'))
                    ->info()
                    ->visible(fn (Get $get): bool => (bool) $get('settings.role_sync') && self::type($get) === IdentityProviderType::Entra)
                    ->columnSpanFull(),
                TextInput::make('settings.role_attribute')
                    ->label(__('idp.roles.attribute'))
                    ->placeholder('http://schemas.microsoft.com/ws/2008/06/identity/claims/groups')
                    ->helperText(__('idp.roles.attribute_help'))
                    ->required(fn (Get $get): bool => (bool) $get('settings.role_sync') && self::type($get) === IdentityProviderType::Saml)
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => (bool) $get('settings.role_sync') && self::type($get) === IdentityProviderType::Saml)
                    ->columnSpanFull(),
                Repeater::make('roleMappings')
                    ->label(__('idp.roles.mappings'))
                    ->relationship()
                    ->schema([
                        TextInput::make('external_value')
                            // Im Repeater-Eintrag: "../../" adressiert das übergeordnete Formular.
                            ->label(fn (Get $get): string => match (self::typeFrom($get('../../type'))) {
                                IdentityProviderType::Saml => __('idp.roles.value_saml'),
                                default => $get('../../settings.role_claim') === 'groups' ? __('idp.roles.value_group') : __('idp.roles.value_role'),
                            })
                            ->required()
                            ->maxLength(500),
                        Select::make('role_id')
                            ->label(__('common.role'))
                            ->relationship(
                                'role',
                                'name',
                                // super-admin ist nie per IdP zuordenbar.
                                modifyQueryUsing: fn (Builder $query): Builder => $query->where('name', '!=', User::ROLE_SUPER_ADMIN),
                            )
                            ->required()
                            ->preload(),
                    ])
                    ->columns(2)
                    ->addActionLabel(__('idp.roles.add'))
                    ->defaultItems(0)
                    ->visible(fn (Get $get): bool => (bool) $get('settings.role_sync'))
                    ->rule(static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                        $keys = array_map(
                            static fn (array $row): string => mb_strtolower(trim((string) ($row['external_value'] ?? ''))).'|'.($row['role_id'] ?? ''),
                            array_values((array) $value),
                        );

                        if (count($keys) !== count(array_unique($keys))) {
                            $fail(__('idp.roles.duplicate'));
                        }
                    })
                    ->columnSpanFull(),
                Toggle::make('settings.require_role_match')
                    ->label(__('idp.roles.require_match'))
                    ->helperText(__('idp.roles.require_match_help'))
                    ->visible(fn (Get $get): bool => (bool) $get('settings.role_sync'))
                    ->columnSpanFull(),
            ]);
    }

    private static function logoutSection(): Section
    {
        return Section::make(__('idp.logout.title'))
            ->columns(2)
            ->schema([
                Toggle::make('settings.single_logout')
                    ->label(__('idp.logout.single_logout'))
                    ->default(false)
                    ->live()
                    ->helperText(fn (Get $get): string => self::type($get) === IdentityProviderType::Entra
                        ? __('idp.logout.entra_help')
                        : __('idp.logout.saml_help'))
                    ->columnSpanFull(),
                self::copyableUrl(__('idp.logout.return_url'), fn (): string => filament()->getLoginUrl())
                    ->visible(fn (Get $get): bool => self::type($get) === IdentityProviderType::Entra && (bool) $get('settings.single_logout')),
                self::copyableUrl('Single Logout Service (SLS, HTTP-Redirect)', fn (Get $get): string => route('auth.saml.sls', self::slug($get)))
                    ->visible(fn (Get $get): bool => self::type($get) === IdentityProviderType::Saml),
                TextInput::make('settings.idp_slo_url')
                    ->label('IdP-SLO-URL')
                    ->helperText(__('idp.logout.slo_url_help'))
                    ->url()
                    ->startsWith(['https://'])
                    ->maxLength(500)
                    ->visible(fn (Get $get): bool => self::type($get) === IdentityProviderType::Saml && blank($get('settings.idp_metadata_url')))
                    ->required(fn (Get $get): bool => (bool) $get('settings.single_logout') && blank($get('settings.idp_metadata_url')))
                    ->columnSpanFull(),
            ]);
    }

    private static function testSection(): Section
    {
        return Section::make(__('idp.test.title'))
            ->visibleOn('edit')
            ->schema([
                Callout::make(fn (?IdentityProvider $record): string => match ($record?->last_test_successful) {
                    true => __('auth_admin.test.success'),
                    false => __('auth_admin.test.failure'),
                    null => __('idp.test.never'),
                })
                    ->description(fn (?IdentityProvider $record): ?string => $record?->last_tested_at === null
                        ? null
                        : AppLocale::dateTime($record->last_tested_at).': '.$record->last_test_message)
                    ->color(fn (?IdentityProvider $record): string => match ($record?->last_test_successful) {
                        true => 'success',
                        false => 'danger',
                        null => 'gray',
                    }),
            ]);
    }

    private static function copyableUrl(string $label, \Closure $url): Text
    {
        return Text::make(fn (Get $get): string => $label.': '.$url($get))
            // copyable() legt nur fest, ob kopiert werden darf; der Inhalt kommt aus copyableState().
            ->copyable()
            ->copyableState(fn (Get $get): string => $url($get))
            ->fontFamily('mono')
            ->columnSpanFull();
    }

    private static function type(Get $get): ?IdentityProviderType
    {
        return self::typeFrom($get('type'));
    }

    private static function typeFrom(mixed $type): ?IdentityProviderType
    {
        return $type instanceof IdentityProviderType ? $type : IdentityProviderType::tryFrom((string) $type);
    }

    private static function slug(Get $get): string
    {
        return filled($get('slug')) ? (string) $get('slug') : 'kennung';
    }
}
