<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\DocumentationImageController;
use App\Http\Controllers\RecordFileController;
use App\Http\Controllers\RoleSimulationController;
use App\Http\Controllers\ThemeController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Keine öffentliche Startseite: direkt in die Anwendung.
Route::redirect('/', '/admin');

// „Ansicht als Rolle“ beenden: außerhalb des Panels, damit es auch mit sehr wenigen Rechten geht.
Route::middleware(['auth'])->post('role-simulation/stop', [RoleSimulationController::class, 'stop'])->name('role-simulation.stop');

// Bilder der Online-Hilfe „Dokumentation“ (aus docs/manual/images, nur angemeldet).
Route::middleware(['auth'])->get('documentation/images/{lang}/{file}', DocumentationImageController::class)
    ->where(['lang' => 'de|en|fr|it', 'file' => '[A-Za-z0-9_-]+\.(png|svg)'])
    ->name('documentation.image');

// Hell/Dunkel/System je Benutzer speichern (Umschalter im Benutzermenü, siehe PanelAppearance::themeScript).
// Dateien in Feldern vom Typ „Datei“: nur mit Leserecht auf Tabelle und Datensatz (B-021).
Route::middleware(['auth'])->get('files/{file}', RecordFileController::class)->whereUuid('file')->name('files.show');

Route::middleware(['auth', 'throttle:30,1'])->post('my-theme', [ThemeController::class, 'store'])->name('my-theme');

// Single Sign-On. {identityProvider} wird über den Slug aufgelöst.
Route::middleware(['guest', 'throttle:30,1'])->prefix('auth')->name('auth.')->group(function (): void {
    Route::get('sso/{identityProvider}/redirect', [SsoController::class, 'redirect'])->name('sso.redirect');
    Route::get('sso/{identityProvider}/callback', [SsoController::class, 'callback'])->name('sso.callback');
    // SAML-ACS Stufe 2 (mit Session), siehe SsoController::samlAcs().
    Route::get('saml/{identityProvider}/acs/{key}', [SsoController::class, 'samlAcsComplete'])
        ->where('key', '[A-Za-z0-9]{40}')
        ->name('saml.acs.complete');
});

// SAML-ACS Stufe 1: Cross-Site-POST vom IdP. Bewusst OHNE Session/Cookies – sonst würde
// eine neue Session das (wegen SameSite=Lax nicht mitgesendete) Session-Cookie überschreiben.
// Kein CSRF-Token möglich; die Prüfung erfolgt über Signatur und InResponseTo in Stufe 2.
Route::post('auth/saml/{identityProvider}/acs', [SsoController::class, 'samlAcs'])
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->middleware('throttle:30,1')
    ->name('auth.saml.acs');

Route::get('auth/saml/{identityProvider}/metadata', [SsoController::class, 'metadata'])->name('auth.saml.metadata');

// SAML Single Logout Service: nur HTTP-Redirect (GET), damit das Session-Cookie
// (SameSite=Lax) mitgesendet wird. Nicht "guest": IdP-initiiert ist der Nutzer angemeldet.
Route::get('auth/saml/{identityProvider}/sls', [SsoController::class, 'samlSls'])
    ->middleware('throttle:30,1')
    ->name('auth.saml.sls');
