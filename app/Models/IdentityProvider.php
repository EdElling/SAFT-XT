<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\AuthConfiguration;
use App\Auth\AuthMode;
use App\Auth\Sso\IdentityProviderType;
use App\Exceptions\BusinessRuleException;
use App\Models\Base\IdentityProvider as BaseIdentityProvider;
use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class IdentityProvider extends BaseIdentityProvider
{
    use HasAuditLog;

    /** Testergebnisse sind keine Konfigurationsänderung. */
    protected array $auditExcept = ['last_tested_at', 'last_test_successful', 'last_test_message'];

    protected array $auditMasked = ['secrets'];

    protected $attributes = [
        'is_enabled' => false,
        'sort_order' => 0,
        'link_by_email' => true,
        'auto_provision' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IdentityProviderType::class,
            'settings' => 'array',
            'secrets' => 'encrypted:array',
            'allowed_domains' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (self $provider): void {
            if (blank($provider->slug)) {
                $provider->slug = Str::slug($provider->name);
            }

            // Geänderte Verbindungsdaten machen einen früheren Test ungültig.
            if ($provider->connectionSettingsChanged() && ! $provider->isDirty('last_test_successful')) {
                $provider->last_test_successful = null;
                $provider->last_tested_at = null;
                $provider->last_test_message = null;
            }
        });

        static::updating(static function (self $provider): void {
            if ($provider->getOriginal('is_enabled') && ! $provider->is_enabled) {
                $provider->assertNotLastSsoProvider();
            }
        });

        static::deleting(static function (self $provider): void {
            if ($provider->is_enabled) {
                $provider->assertNotLastSsoProvider();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function defaultRole(): BelongsTo
    {
        return $this->role();
    }

    public function socialAccounts(): HasMany
    {
        return $this->social_accounts();
    }

    public function roleMappings(): HasMany
    {
        return $this->hasMany(IdentityProviderRoleMapping::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function enabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function secret(string $key): ?string
    {
        $value = data_get($this->secrets ?? [], $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hasSecret(string $key): bool
    {
        return $this->secret($key) !== null;
    }

    /**
     * @return list<string>
     */
    public function allowedDomains(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $d): string => mb_strtolower(trim((string) $d), 'UTF-8'),
            $this->allowed_domains ?? [],
        )));
    }

    // --- URLs, die beim IdP hinterlegt werden müssen ---------------------------------

    public function callbackUrl(): string
    {
        return route('auth.sso.callback', $this);
    }

    public function acsUrl(): string
    {
        return route('auth.saml.acs', $this);
    }

    public function metadataUrl(): string
    {
        return route('auth.saml.metadata', $this);
    }

    public function slsUrl(): string
    {
        return route('auth.saml.sls', $this);
    }

    public function spEntityId(): string
    {
        return (string) ($this->setting('sp_entity_id') ?: $this->metadataUrl());
    }

    /** Einstellungen ohne Einfluss auf die Verbindung (Änderung erfordert keinen neuen Test). */
    private const array NON_CONNECTION_SETTINGS = ['single_logout', 'role_sync', 'role_claim', 'role_attribute', 'require_role_match', 'tenant_guid'];

    private function connectionSettingsChanged(): bool
    {
        if ($this->isDirty(['type', 'secrets'])) {
            return true;
        }

        if (! $this->isDirty('settings')) {
            return false;
        }

        return $this->connectionSettings($this->getOriginal('settings')) !== $this->connectionSettings($this->settings);
    }

    /**
     * Verbindungsrelevante Einstellungen, normalisiert: leere Werte (null, '') gelten
     * als nicht gesetzt – das Formular schreibt leere Felder als null zurück.
     *
     * @return array<string, mixed>
     */
    private function connectionSettings(mixed $settings): array
    {
        $relevant = array_filter(
            Arr::except((array) ($settings ?? []), self::NON_CONNECTION_SETTINGS),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
        ksort($relevant);

        return $relevant;
    }

    /**
     * Schutz gegen Aussperren: im SSO-Modus muss ein aktiver IdP verbleiben.
     */
    private function assertNotLastSsoProvider(): void
    {
        $config = app(AuthConfiguration::class);

        if ($config->isForcedLocal() || $config->settings()->mode !== AuthMode::Sso) {
            return;
        }

        $othersEnabled = self::query()->enabled()->whereKeyNot($this->getKey())->exists();

        if (! $othersEnabled) {
            throw new BusinessRuleException(__('auth_admin.errors.last_provider'), 'sso.last_provider');
        }
    }
}
