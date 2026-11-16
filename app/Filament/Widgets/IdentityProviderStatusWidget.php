<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Auth\AuthConfiguration;
use App\Auth\Sso\IdentityProviderType;
use App\Auth\Sso\Saml\Certificates;
use App\Filament\Resources\IdentityProviders\IdentityProviderResource;
use App\Filament\Widgets\Concerns\RequiresPermissions;
use App\Models\IdentityProvider;
use App\Support\AppLocale;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class IdentityProviderStatusWidget extends TableWidget
{
    use RequiresPermissions;

    /** Ab dieser Restlaufzeit wird ein Zertifikat als Warnung markiert. */
    public const int CERTIFICATE_WARNING_DAYS = 30;

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = 'full';

    protected static function requiredPermissions(): array
    {
        return ['widgets.idp_status', 'identity_providers.view'];
    }

    public function table(Table $table): Table
    {
        $config = app(AuthConfiguration::class);

        return $table
            ->heading(__('dashboard.idp_status'))
            ->query(fn () => IdentityProvider::query()->orderBy('sort_order')->orderBy('name'))
            ->paginated(false)
            ->description(($config->isForcedLocal() ? __('dashboard.idp.forced_local').' ' : '')
                .__('dashboard.idp.mode', ['mode' => $config->mode()->label()]))
            ->recordUrl(fn (IdentityProvider $record): ?string => IdentityProviderResource::canEdit($record)
                ? IdentityProviderResource::getUrl('edit', ['record' => $record])
                : null)
            ->columns([
                TextColumn::make('name')->label(__('common.name')),
                TextColumn::make('type')
                    ->label(__('common.type'))
                    ->badge()
                    ->formatStateUsing(fn (IdentityProviderType $state): string => $state->label()),
                IconColumn::make('is_enabled')->label(__('common.active'))->boolean(),
                IconColumn::make('last_test_successful')
                    ->label(__('idp.table.test'))
                    ->icon(fn (?bool $state): string => match ($state) {
                        true => 'heroicon-o-check-circle',
                        false => 'heroicon-o-x-circle',
                        null => 'heroicon-o-question-mark-circle',
                    })
                    ->color(fn (?bool $state): string => match ($state) {
                        true => 'success',
                        false => 'danger',
                        null => 'warning',
                    })
                    ->tooltip(fn (IdentityProvider $record): string => $record->last_test_message ?? __('dashboard.idp.not_tested')),
                TextColumn::make('last_tested_at')
                    ->label(__('dashboard.idp.last_tested'))
                    ->since()
                    ->placeholder(__('dashboard.idp.never')),
                TextColumn::make('certificate_expiry')
                    ->label(__('dashboard.idp.certificate'))
                    ->state(fn (IdentityProvider $record): ?string => AppLocale::date(self::earliestCertificateExpiry($record)))
                    ->description(fn (IdentityProvider $record): ?string => self::certificateDescription($record))
                    ->color(fn (IdentityProvider $record): ?string => self::certificateColor($record))
                    ->placeholder(fn (IdentityProvider $record): string => $record->type === IdentityProviderType::Entra
                        ? __('dashboard.idp.check_secret')
                        : __('dashboard.idp.via_metadata')),
            ])
            ->emptyStateHeading(__('dashboard.idp.none'));
    }

    /** Frühestes Ablaufdatum aus manuellem IdP-Zertifikat und (aktivem) SP-Zertifikat. */
    public static function earliestCertificateExpiry(IdentityProvider $provider): ?CarbonImmutable
    {
        if ($provider->type !== IdentityProviderType::Saml) {
            return null;
        }

        $candidates = [];

        if (filled($provider->setting('idp_certificate'))) {
            $candidates[] = Certificates::validTo((string) $provider->setting('idp_certificate'));
        }

        if ($provider->setting('sign_requests') && filled($provider->setting('sp_certificate'))) {
            $candidates[] = Certificates::validTo((string) $provider->setting('sp_certificate'));
        }

        $dates = array_filter($candidates);

        return $dates === [] ? null : min($dates);
    }

    private static function certificateDescription(IdentityProvider $provider): ?string
    {
        $expiry = self::earliestCertificateExpiry($provider);

        if ($expiry === null) {
            return null;
        }

        $days = (int) floor(now()->diffInDays($expiry, false));

        return $days < 0 ? __('dashboard.idp.expired') : trans_choice('dashboard.idp.in_days', $days, ['days' => $days]);
    }

    private static function certificateColor(IdentityProvider $provider): ?string
    {
        $expiry = self::earliestCertificateExpiry($provider);

        return match (true) {
            $expiry === null => null,
            $expiry->isPast() => 'danger',
            $expiry->lessThan(now()->addDays(self::CERTIFICATE_WARNING_DAYS)) => 'warning',
            default => 'success',
        };
    }
}
