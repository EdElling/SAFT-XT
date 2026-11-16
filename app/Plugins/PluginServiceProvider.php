<?php

declare(strict_types=1);

namespace App\Plugins;

use Illuminate\Support\ServiceProvider;

/**
 * Basis für den ServiceProvider eines Plugins (in der composer.json des Plugins unter extra.laravel.providers).
 * Meldet das Plugin an und lädt – falls vorhanden – Übersetzungen (lang/), Views (resources/views/) und
 * Migrationen (database/migrations/), jeweils unter der id des Plugins als Namensraum: __('<id>::datei.schluessel'),
 * view('<id>::name').
 */
abstract class PluginServiceProvider extends ServiceProvider
{
    /** @return class-string<SaftPlugin> */
    abstract protected function plugin(): string;

    private ?SaftPlugin $instance = null;

    public function register(): void
    {
        $this->app->singletonIf(PluginRegistry::class);
        $this->app->make(PluginRegistry::class)->add($this->instance = $this->app->make($this->plugin()));
    }

    public function boot(): void
    {
        $plugin = $this->instance ?? $this->app->make($this->plugin());
        $base = $plugin->basePath();

        if (is_dir($base.'/lang')) {
            $this->loadTranslationsFrom($base.'/lang', $plugin->id());
        }

        if (is_dir($base.'/resources/views')) {
            $this->loadViewsFrom($base.'/resources/views', $plugin->id());
        }

        if (is_dir($base.'/database/migrations')) {
            $this->loadMigrationsFrom($base.'/database/migrations');
        }

        $this->app->make(PluginRegistry::class)->listen($plugin);
    }
}
