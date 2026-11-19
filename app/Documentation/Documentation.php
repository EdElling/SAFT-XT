<?php

declare(strict_types=1);

namespace App\Documentation;

use App\Apps\CurrentApp;
use App\Apps\PluginApps;
use App\Models\App;
use App\Models\User;
use App\Plugins\Api\Manual;
use App\Plugins\PluginDependencies;
use App\Plugins\PluginRegistry;
use App\Plugins\SaftPlugin;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Online-Hilfe aus den Handbüchern in docs/manual (eine Quelle, keine Kopie): Anwenderhandbuch und
 * Häufige Fragen für alle, das Administratorhandbuch für Personen mit einem Recht im Bereich
 * Administration. Die Systemadministration (Betrieb, Installation) erscheint bewusst nicht.
 *
 * Deutsch für deutsche Oberfläche, sonst Englisch. Überschriften erhalten je Handbuch eindeutige Anker
 * („user-41-die-tabellenansicht“); Verweise in nicht sichtbare Handbücher werden zu Text.
 */
final class Documentation
{
    /** Kennung => Dateiname ohne Sprache (docs/manual/<NAME>[.de].md) */
    public const array DOCS = ['user' => 'USER_GUIDE', 'faq' => 'FAQ', 'admin' => 'ADMIN_GUIDE'];

    /** Wer eines dieser Rechte hat, sieht den Bereich Administration – und das Administratorhandbuch. */
    public const array ADMIN_PERMISSIONS = [
        'users.view', 'roles.view', 'menus.view', 'activity_log.view', 'identity_providers.view', 'settings.auth.view',
        'settings.app.view', 'custom_tables.view', 'database_connections.view', 'table_connector.view', 'report_links.view',
    ];

    public static function isAdmin(?User $user): bool
    {
        return $user !== null && collect(self::ADMIN_PERMISSIONS)->contains(fn (string $p): bool => $user->can($p));
    }

    /** @return list<string> sichtbare Handbücher in Reihenfolge: die von SAFT, dann die der Plugins */
    public static function visibleFor(?User $user): array
    {
        $core = array_filter(array_keys(self::DOCS), fn (string $doc): bool => $doc !== 'admin' || self::isAdmin($user));
        // Handbücher von Plugins einer App nur in dieser App (B-107, PluginApps).
        $currentApp = $user === null ? null : app(CurrentApp::class)->id($user);
        $plugins = array_filter(self::pluginManuals(), static fn (array $entry): bool => $user !== null
            && app(PluginApps::class)->belongsTo($entry['plugin'], $currentApp)
            && (
                $entry['manual']->permissions === []
                || array_filter($entry['manual']->permissions, static fn (string $permission): bool => $user->can($permission)) !== []
            ));

        // Handbuch der aktuellen App (B-107): nur solange diese App die aktuelle ist.
        $app = $user === null ? null : app(CurrentApp::class)->get($user);
        $own = $app !== null && isset(self::appManuals()[self::APP_PREFIX.$app->id]) ? [self::APP_PREFIX.$app->id] : [];

        return [...array_values($core), ...$own, ...array_keys($plugins)];
    }

    /** Alle Handbücher, auch nicht sichtbare (Kennungen). @return list<string> */
    public static function keys(): array
    {
        return [...array_keys(self::DOCS), ...array_keys(self::appManuals()), ...array_keys(self::pluginManuals())];
    }

    /** Kennung eines App-Handbuchs: „app-<id>“, zugleich Präfix der Anker. */
    public const string APP_PREFIX = 'app-';

    /**
     * Apps mit Handbuch (B-107), je Anfrage einmal geladen.
     *
     * @return array<string, App>
     */
    public static function appManuals(): array
    {
        return once(static fn (): array => App::query()->whereHas('manuals')->with('manuals')->orderBy('sort_order')->get()
            ->mapWithKeys(static fn (App $app): array => [self::APP_PREFIX.$app->id => $app])->all());
    }

    /** Titel eines Handbuchs in der Sprache der Oberfläche. */
    public static function label(string $doc): string
    {
        if (($app = self::appManuals()[$doc] ?? null) !== null) {
            return __('documentation.app_manual', ['app' => (string) $app->name]);
        }

        $entry = self::pluginManuals()[$doc] ?? null;

        if ($entry === null) {
            return __('documentation.docs.'.$doc);
        }

        $title = $entry['manual']->title;

        return (string) ($title[app()->getLocale()] ?? $title['en'] ?? reset($title));
    }

    /**
     * Handbücher der Plugins, die wirken (eingeschaltet, nicht ruhend), mit vorhandenen Dateien. Kennung
     * „plugin-<plugin-id>-<handbuch-id>“, zugleich Präfix der Anker.
     *
     * @return array<string, array{plugin: SaftPlugin, manual: Manual}>
     */
    public static function pluginManuals(): array
    {
        $manuals = [];

        foreach (app(PluginRegistry::class)->all() as $plugin) {
            if ($plugin->manuals() === [] || ! app(PluginDependencies::class)->satisfied($plugin)) {
                continue;
            }

            foreach ($plugin->manuals() as $manual) {
                if (preg_match(Manual::ID_PATTERN, $manual->id) === 1 && self::pluginFile($plugin, $manual, 'en') !== null) {
                    $manuals['plugin-'.$plugin->id().'-'.$manual->id] = ['plugin' => $plugin, 'manual' => $manual];
                }
            }
        }

        return $manuals;
    }

    /** Datei eines Plugin-Handbuchs in dieser Sprache, sonst Englisch, sonst die erste vorhandene; null = keine. */
    private static function pluginFile(SaftPlugin $plugin, Manual $manual, string $language): ?string
    {
        foreach (array_unique([$language, 'en', ...Manual::LANGUAGES]) as $candidate) {
            $file = $plugin->basePath().'/'.trim($manual->path, '/').'/'.$candidate.'.md';

            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /** Sprachen, in denen Handbücher erscheinen können; Englisch ist die Grundfassung (ohne Kürzel im Dateinamen). */
    public const array LANGUAGES = ['de', 'fr', 'it'];

    /**
     * Sprache eines Handbuchs: die der Oberfläche, wenn es die Datei in dieser Sprache gibt, sonst Englisch
     * (Handbücher werden einzeln übersetzt).
     */
    public static function language(?string $doc = null): string
    {
        $locale = app()->getLocale();

        if ($doc !== null && ($app = self::appManuals()[$doc] ?? null) !== null) {
            $locales = $app->manuals->pluck('locale')->all();

            return in_array($locale, $locales, true) ? $locale : (in_array((string) config('locales.default'), $locales, true) ? (string) config('locales.default') : (string) ($locales[0] ?? $locale));
        }

        if ($doc !== null && ($entry = self::pluginManuals()[$doc] ?? null) !== null) {
            return basename((string) self::pluginFile($entry['plugin'], $entry['manual'], $locale), '.md');
        }

        if (! in_array($locale, self::LANGUAGES, true)) {
            return 'en';
        }

        return $doc === null || is_file(self::path($doc, $locale)) ? $locale : 'en';
    }

    private static function path(string $doc, string $language): string
    {
        if (($entry = self::pluginManuals()[$doc] ?? null) !== null) {
            return (string) self::pluginFile($entry['plugin'], $entry['manual'], $language);
        }

        // Unbekannt oder Handbuch eines ruhenden Plugins: keine Datei.
        if (! isset(self::DOCS[$doc])) {
            return '';
        }

        return base_path('docs/manual/'.self::DOCS[$doc].($language === 'en' ? '' : '.'.$language).'.md');
    }

    /**
     * Handbuch als Abschnitte (je Überschrift der Ebene 2 oder 3) für Inhaltsverzeichnis und Suche.
     *
     * @param  list<string>  $visible  sichtbare Handbücher (für Verweise)
     * @return list<array{id: string, level: int, title: string, html: string, text: string}>
     */
    public static function sections(string $doc, array $visible): array
    {
        $html = self::render($doc, $visible);
        $parts = preg_split('#(?=<h[23] id=")#', $html, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sections = [];

        foreach ($parts as $part) {
            if (preg_match('#^<h([23]) id="([^"]+)">(.*?)</h\1>#s', $part, $m) !== 1) {
                // Text vor der ersten Überschrift an den ersten Abschnitt hängen.
                $sections[0]['html'] = ($sections[0]['html'] ?? '').$part;

                continue;
            }

            $title = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5));
            $sections[] = [
                'id' => $m[2],
                'level' => (int) $m[1],
                'title' => $title,
                'html' => $part,
                'text' => mb_strtolower(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_HTML5)) ?? ''),
            ];
        }

        return array_values(array_filter($sections, fn (array $s): bool => isset($s['id'])));
    }

    /** @param  list<string>  $visible */
    public static function render(string $doc, array $visible): string
    {
        $html = self::markdown(self::body($doc));

        // Anker wie auf GitHub, je Handbuch eindeutig.
        $html = preg_replace_callback('#<h([2-4])>(.*?)</h\1>#s', fn (array $m): string => sprintf(
            '<h%1$s id="%2$s">%3$s</h%1$s>',
            $m[1],
            $doc.'-'.self::slug(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5)),
            $m[2],
        ), $html) ?? $html;

        // Bilder über die geschützte Route.
        $html = preg_replace_callback('#<img src="images/(de|en|fr|it)/([A-Za-z0-9._-]+)"#', fn (array $m): string => '<img loading="lazy" src="'.e(route('documentation.image', ['lang' => $m[1], 'file' => $m[2]])).'"', $html) ?? $html;

        return preg_replace_callback('#<a href="([^"]*)"([^>]*)>(.*?)</a>#s', fn (array $m): string => self::link($doc, $visible, rawurldecode(html_entity_decode($m[1])), $m[3]), $html) ?? $html;
    }

    /** Markdown mit Tabellen (GitHub-Variante) als HTML; eingebettetes HTML wird nicht ausgeführt. */
    public static function markdown(string $markdown): string
    {
        $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }

    /** Komponenten von Drittanbietern (THIRD_PARTY_NOTICES.md) als HTML, ohne die Hauptüberschrift. */
    public static function thirdPartyNotices(): ?string
    {
        $text = @file_get_contents(base_path('THIRD_PARTY_NOTICES.md'));

        return $text === false ? null : self::markdown((string) preg_replace('/^# .*\n/', '', $text));
    }

    /** Markdown eines Handbuchs ohne Kopf, in der Sprache der Oberfläche (für den Hilfe-Assistenten). */
    public static function source(string $doc): string
    {
        return self::body($doc);
    }

    /** Markdown ohne Kopf (Titel, Sprachwahl, eigenes Inhaltsverzeichnis): alles nach der zweiten Trennlinie. */
    private static function body(string $doc): string
    {
        // Handbuch einer App: ganzer Text, ohne eine Überschrift der Ebene 1 am Anfang.
        if (($app = self::appManuals()[$doc] ?? null) !== null) {
            return (string) preg_replace('/\A\s*# [^\n]*\n/', '', (string) $app->manual(self::language($doc)));
        }

        $file = self::path($doc, self::language($doc));

        // Handbücher aus Plugins: ganze Datei, ohne eine Überschrift der Ebene 1 am Anfang (der Reiter trägt den Titel).
        if (isset(self::pluginManuals()[$doc])) {
            return (string) preg_replace('/\A\s*# [^\n]*\n/', '', (string) file_get_contents($file));
        }

        $parts = preg_split('/^---$/m', (string) file_get_contents($file), 3);

        return count($parts) === 3 ? $parts[2] : (string) end($parts);
    }

    /** @param  list<string>  $visible */
    private static function link(string $doc, array $visible, string $href, string $label): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return '<a href="'.e($href).'" target="_blank" rel="noopener">'.$label.'</a>';
        }

        if (str_starts_with($href, '#')) {
            return '<a href="#'.e($doc.'-'.substr($href, 1)).'" data-doc-link>'.$label.'</a>';
        }

        if (preg_match('/^([A-Z_]+)(?:\.[a-z]{2})?\.md#(.+)$/', $href, $m) === 1) {
            $target = array_search($m[1], self::DOCS, true);

            if ($target !== false && in_array($target, $visible, true)) {
                return '<a href="#'.e($target.'-'.$m[2]).'" data-doc-link>'.$label.'</a>';
            }
        }

        // Nicht sichtbares Handbuch, Systemadministration, Installationsanleitungen: nur der Text.
        return '<span class="saft-doc-unlinked">'.$label.'</span>';
    }

    /** Anker wie GitHub: klein, ohne Satzzeichen, Leerzeichen zu Bindestrichen. */
    public static function slug(string $title): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}_\- ]/u', '', mb_strtolower(trim($title))) ?? '';

        return str_replace(' ', '-', $slug);
    }
}
