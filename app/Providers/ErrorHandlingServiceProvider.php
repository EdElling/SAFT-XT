<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\Database\DatabaseErrorClassifier;
use App\Exceptions\ErrorPresenter;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use Livewire\Livewire;
use Throwable;

/**
 * Verbindet den zentralen ErrorPresenter mit Livewire/Filament: bekannte Fehler
 * erscheinen als Notification, die Seite bleibt bedienbar.
 */
class ErrorHandlingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseErrorClassifier::class);

        $this->app->singleton(ErrorPresenter::class, fn ($app): ErrorPresenter => new ErrorPresenter(
            $app->make(DatabaseErrorClassifier::class),
            (bool) $app['config']->get('app.debug'),
        ));
    }

    public function boot(): void
    {
        Livewire::listen('exception', function (mixed $target, Throwable $e, callable $stopPropagation): void {
            // Nur bei Update-Requests (Aktionen auf geladener Seite). Beim initialen
            // Rendern (mount) müssen 403/404 regulär greifen, sonst würde die Seite
            // trotz fehlender Berechtigung oder fehlendem Datensatz gerendert.
            if (! $target instanceof Component || ! Livewire::isLivewireRequest() || ! $this->shouldHandleInLivewire($e)) {
                return;
            }

            report($e);

            $error = $this->app->make(ErrorPresenter::class)->present($e);

            $notification = Notification::make()
                ->title($error->title)
                ->body($error->body().($error->debug !== null ? "\n\n".($error->debug['message'] ?? '') : ''))
                ->persistent();

            ($error->status === 409 ? $notification->warning() : $notification->danger())->send();

            $stopPropagation();
        });
    }

    /**
     * Nur bekannte, nutzerrelevante Fehler. Validierung und Filament-Steuer-Exceptions
     * (Halt, Cancel) behandeln Livewire/Filament selbst; Unerwartetes läuft regulär
     * weiter (Report + Filament-Status-Notification).
     */
    private function shouldHandleInLivewire(Throwable $e): bool
    {
        return $e instanceof BusinessRuleException
            || $e instanceof AuthorizationException
            || $e instanceof ModelNotFoundException
            || $this->app->make(DatabaseErrorClassifier::class)->classify($e) !== null;
    }
}
