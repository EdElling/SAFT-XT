<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Http\Controllers\ThemeController;
use App\Models\User;
use App\Settings\AppSettings;
use App\Support\ColorScheme;
use App\Support\HighContrast;
use Filament\Support\Facades\FilamentColor;
use Throwable;

/**
 * Darstellung des Panels: Farbschema aus den App-Einstellungen (für alle), eigene Stile und
 * der persönliche Kontrastmodus.
 *
 * Hoher Kontrast verschiebt die mittleren Abstufungen aller Filament-Farben (Grau, Hauptfarbe,
 * Erfolg, Warnung …) zur Gegenseite des Hintergrunds – im hellen Modus dunkler, im dunklen heller –
 * und verstärkt Rahmen, Fokus, Links und die Markierung der ausgewählten Zeile.
 */
final class PanelAppearance
{
    /** Hell: Abstufung => dunklere Abstufung, deren Wert sie im Kontrastmodus annimmt. */
    private const array LIGHT_SHIFT = [400 => 600, 500 => 700, 600 => 800, 700 => 900, 800 => 950];

    /** Dunkel: Abstufung => hellere Abstufung. */
    private const array DARK_SHIFT = [500 => 300, 400 => 200, 300 => 100, 200 => 50];

    public static function scheme(): ColorScheme
    {
        try {
            return ColorScheme::tryFrom(app(AppSettings::class)->color_scheme) ?? ColorScheme::Indigo;
        } catch (Throwable) {
            // Neuinstallation vor „migrate“: Vorgabe.
            return ColorScheme::Indigo;
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function colors(): array
    {
        return ['primary' => self::scheme()->palette()];
    }

    public static function highContrast(): HighContrast
    {
        $user = auth()->user();

        return HighContrast::fromStored($user instanceof User ? $user->high_contrast : null);
    }

    /** Setzt die Kontrastklasse am html-Element, bevor die Seite gezeichnet wird. */
    public static function headScript(): string
    {
        $class = self::highContrast()->cssClass();
        $add = $class === null ? '' : "c.add('{$class}');";

        return "<script>(function(){var c=document.documentElement.classList;c.remove('saft-hc','saft-hc-auto');{$add}})();</script>";
    }

    /**
     * Hell/Dunkel/System des angemeldeten Benutzers – vor Filaments eigenem Skript, das den Wert aus dem Browser
     * liest (sonst erbte der nächste Benutzer im selben Browser die Wahl). Ohne eigene Wahl: System. Beim
     * Umschalten im Benutzermenü (Filament-Ereignis „theme-changed“) wird die Wahl beim Konto gespeichert.
     */
    public static function themeScript(): string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return '';
        }

        $theme = in_array($user->theme, ThemeController::THEMES, true) ? $user->theme : 'system';
        $url = json_encode(route('my-theme'), JSON_UNESCAPED_SLASHES);
        $token = json_encode(csrf_token());

        // Filaments Umschalter meldet „theme-changed“ auch beim Laden jeder Seite; gespeichert wird nur eine echte
        // Änderung (sonst überschreibt eine noch laufende Seite einen gerade gewählten Wert).
        return '<script>(function(){'
            ."var current='{$theme}';"
            ."localStorage.setItem('filament:theme:mode',current);"
            ."window.addEventListener('theme-changed',function(e){"
            .'if(e.detail===current){return;}current=e.detail;'
            ."fetch({$url},{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':{$token}},body:JSON.stringify({theme:e.detail})});"
            .'});})();</script>';
    }

    public static function styles(): string
    {
        $highlight = self::scheme()->highlight() ?? 'var(--primary-500)';

        return '<style>'
            .":root{--saft-highlight:{$highlight}}"
            // Herkunft aus einem Plugin (PluginOrigin): kleines Etikett unter dem Seitentitel bzw. unter einer Kachel.
            .'.saft-plugin-origin{margin-top:.25rem}.saft-plugin-origin-card{text-align:end}'
            .'.saft-plugin-origin-badge{display:inline-flex;align-items:center;gap:.25rem;font-size:.75rem;line-height:1rem;'
            .'padding:.125rem .5rem;border-radius:9999px;color:var(--gray-600);background:var(--gray-100)}'
            .'.dark .saft-plugin-origin-badge{color:var(--gray-300);background:color-mix(in oklab,var(--gray-700) 60%,transparent)}'
            .'a.saft-plugin-origin-badge:hover{color:var(--primary-600)}.dark a.saft-plugin-origin-badge:hover{color:var(--primary-400)}'
            .'.saft-plugin-origin-icon{width:.875rem;height:.875rem;flex-shrink:0}'
            // Prüfregeln aus Plugins in der Auswahl „Weitere Prüfungen“: Symbol, Regel, Plugin (grau).
            .'.saft-plugin-option{display:inline-flex;align-items:center;gap:.3rem}'
            .'.saft-plugin-option-source{opacity:.65;font-size:.85em}'
            // Untermenüs in der Seitenleiste: Pfeil rechts, nach unten gedreht, wenn offen (MenuNavigationBuilder).
            .'.fi-sidebar-item-label:has(> .saft-submenu-chevron){display:flex;align-items:center;justify-content:space-between;gap:.5rem}'
            .'.saft-submenu-chevron{width:1rem;height:1rem;flex-shrink:0;opacity:.55;transition:transform .15s ease}'
            .':is(.fi-sidebar-item.fi-active,.fi-sidebar-item.fi-sidebar-item-has-active-child-items) > .fi-sidebar-item-btn .saft-submenu-chevron{transform:rotate(90deg)}'
            // Technische Details eines fehlgeschlagenen Laufs (geplante Aufgaben).
            .'.saft-error-detail{font-size:.875rem}.saft-error-detail-hint{color:var(--gray-500);margin-bottom:.75rem}'
            .'.saft-error-detail dt{font-weight:600;margin-top:.75rem}.saft-error-detail dd{margin-top:.25rem}'
            .'.saft-error-detail pre,.saft-error-detail code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.8rem}'
            .'.saft-error-detail pre{white-space:pre-wrap;word-break:break-word;background:var(--gray-50);padding:.5rem .75rem;border-radius:.375rem;max-height:20rem;overflow:auto}'
            .'.dark .saft-error-detail pre{background:color-mix(in oklab,var(--gray-800) 70%,transparent)}'
            // Hervorhebung der ausgewählten Zeile in der Master-Detail-Kaskade (Filament-Farbvariablen, Dark Mode).
            .'.saft-row-selected > td{background-color:color-mix(in oklab,var(--saft-highlight) 14%,transparent)!important}'
            .'.saft-row-selected > td:first-child{box-shadow:inset 3px 0 0 var(--saft-highlight)}'
            // Seiten mit fester Breite, aber linksbündig (statt mittig), z. B. App-Einstellungen.
            .'body.saft-content-left .fi-main{margin-inline:0}'
            .'.fi-sidebar.fi-sidebar-open{min-width:14rem;max-width:calc(100vw - 3rem)}'
            .'@media (min-width:1024px){.fi-sidebar.fi-sidebar-open{max-width:min(28rem,45vw)}}'
            // Datenansicht (Tabellen, Kurzanzeigen, Reiter), Admin-Listen und Tabellen in Bearbeitungsmasken
            // nur so breit wie ihr Inhalt, höchstens so breit wie der Platz; breitere scrollen wie bisher.
            // Nicht auf dem Handy und nicht in den Dashboard-Kacheln.
            .'@media (min-width:640px){'
            .':is(.saft-data-page .fi-page-content :is(.fi-ta,.fi-sc-section,.fi-sc-tabs),'
            .'.fi-resource-list-records-page .fi-page-content .fi-ta,.fi-resource-relation-manager .fi-ta)'
            .'{width:fit-content;max-width:100%}'
            // Tabelle mit ihrer Kurzanzeige: der Behälter so breit wie die Tabelle, die Kurzanzeige füllt ihn.
            // Mindestbreite wie bei den Tabellen (unten): Im Behälter ließe sie sich nicht auflösen (100 % von
            // „so breit wie der Inhalt“), die Blätterleiste würde zu schmal. Die Tabelle füllt den Behälter.
            .'.saft-data-page .saft-table-with-record{width:fit-content;max-width:100%;min-width:min(100%,46rem)}'
            .'.saft-data-page .saft-table-with-record .fi-ta{width:100%}'
            // Filament kürzt die Blätterleiste schmaler Tabellen; „1 bis 10 von 30“ und „pro Seite“ bleiben sichtbar.
            .':is(.saft-data-page,.fi-resource-list-records-page,.fi-resource-relation-manager) .fi-pagination'
            .' .fi-pagination-overview{display:inline}'
            .':is(.saft-data-page,.fi-resource-list-records-page,.fi-resource-relation-manager) .fi-pagination'
            .' .fi-pagination-records-per-page-select:not(.fi-compact){display:inline}'
            .':is(.saft-data-page,.fi-resource-list-records-page,.fi-resource-relation-manager) .fi-pagination'
            .' .fi-pagination-records-per-page-select.fi-compact{display:none}'
            // Seitenzahlen mit Pfeilen statt „Vorherige/Nächste“, sobald die Blätterleiste Platz dafür hat
            // (Filament schaltet erst ab 56rem um; die Leiste ist selbst der Container der Abfrage).
            .'@container (min-width:40rem){'
            .':is(.saft-data-page,.fi-resource-list-records-page,.fi-resource-relation-manager) .fi-pagination:not(.fi-simple)'
            .' :is(.fi-pagination-previous-btn,.fi-pagination-next-btn){display:none}'
            .':is(.saft-data-page,.fi-resource-list-records-page,.fi-resource-relation-manager) .fi-pagination'
            .' .fi-pagination-items{display:flex}}'
            // Mindestbreite, damit Übersicht, „pro Seite“ und Seitenzahlen auch unter schmalen Tabellen Platz haben.
            .':is(.saft-data-page .fi-page-content .fi-ta,.fi-resource-list-records-page .fi-page-content .fi-ta,'
            .'.fi-resource-relation-manager .fi-ta){min-width:min(100%,46rem)}}'
            .self::highContrastStyles('html.saft-hc')
            .'@media (prefers-contrast:more){'.self::highContrastStyles('html.saft-hc-auto').'}'
            .'</style>';
    }

    private static function highContrastStyles(string $scope): string
    {
        $light = [];
        $dark = [];

        foreach (FilamentColor::getColors() as $name => $palette) {
            foreach (self::LIGHT_SHIFT as $shade => $target) {
                if (isset($palette[$shade], $palette[$target])) {
                    $light[] = "--{$name}-{$shade}:{$palette[$target]}";
                }
            }

            foreach (self::DARK_SHIFT as $shade => $target) {
                if (isset($palette[$shade], $palette[$target])) {
                    $dark[] = "--{$name}-{$shade}:{$palette[$target]}";
                }
            }
        }

        return "{$scope}:not(.dark){".implode(';', $light).'}'
            ."{$scope}.dark{".implode(';', $dark).'}'
            // Platzhalter wie Hilfstexte, nicht blasser.
            ."{$scope} ::placeholder{color:var(--gray-500)!important;opacity:1}"
            // Deutlicher Tastaturfokus.
            ."{$scope} :focus-visible{outline:3px solid var(--primary-600)!important;outline-offset:2px}"
            ."{$scope}.dark :focus-visible{outline-color:var(--primary-400)!important}"
            // Kräftige Rahmen um Eingaben, Abschnitte, Tabellen und Fenster.
            ."{$scope} :is(.fi-input-wrp,.fi-section,.fi-ta-ctn,.fi-modal-window,.fi-dropdown-panel){box-shadow:0 0 0 1px var(--gray-500)!important}"
            // Links unterstrichen.
            ."{$scope} :is(a.fi-link,.fi-ta-text a,.fi-in-text a,.fi-fo-field-wrp-hint a){text-decoration:underline}"
            // Aktiver Menüeintrag und ausgewählte Zeile nicht nur über die Farbe.
            ."{$scope} .fi-sidebar-item.fi-active > .fi-sidebar-item-btn{box-shadow:inset 3px 0 0 var(--primary-600)}"
            ."{$scope}.dark .fi-sidebar-item.fi-active > .fi-sidebar-item-btn{box-shadow:inset 3px 0 0 var(--primary-400)}"
            ."{$scope} .saft-row-selected > td{border-block:2px solid var(--saft-highlight)}"
            // Farbige Schaltflächen: weiße Schrift auf dunklem Grund (mind. 7:1), auch im dunklen Modus,
            // in dem die übrigen Abstufungen für Text heller werden.
            ."{$scope} .fi-btn.fi-color:not(.fi-outlined){background-color:var(--color-600)!important;color:#fff!important}"
            ."{$scope} .fi-btn.fi-color:not(.fi-outlined):hover{background-color:var(--color-700)!important}"
            ."{$scope}.dark .fi-btn.fi-color:not(.fi-outlined){background-color:var(--color-800)!important}"
            ."{$scope}.dark .fi-btn.fi-color:not(.fi-outlined):hover{background-color:var(--color-900)!important}"
            ."{$scope} .fi-btn.fi-color:not(.fi-outlined) :is(.fi-icon,svg){color:#fff!important}";
    }
}
