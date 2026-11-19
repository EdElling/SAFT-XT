<?php

declare(strict_types=1);

namespace App\Support;

use BladeUI\Icons\Factory;
use Illuminate\Support\Facades\File;

/**
 * Auswählbare Icons der installierten Blade-Icon-Sets, für den Auswahldialog (IconPicker).
 * Heroicons nach Stil getrennt (Outline, Solid, Mini, Micro); weitere Sets je eine Gruppe.
 * Filaments internes Set (fi-…) wird nicht angeboten.
 */
final class IconCatalog
{
    /** Heroicons: Präfix im Namen => Stil, in der Reihenfolge der Reiter. */
    public const array HEROICON_STYLES = ['o' => 'outline', 's' => 'solid', 'm' => 'mini', 'c' => 'micro'];

    private const array HIDDEN_SETS = ['filament'];

    /** @var list<array{key: string, icons: list<string>}>|null Icon-Namen ändern sich nur mit den Paketen. */
    private static ?array $groups = null;

    /**
     * Gruppen mit Beschriftung in der aktuellen Sprache.
     *
     * @return list<array{key: string, label: string, icons: list<string>}>
     */
    public static function groups(): array
    {
        return array_map(static fn (array $group): array => [
            'key' => $group['key'],
            'label' => in_array($group['key'], self::HEROICON_STYLES, true) ? __("common.icon_picker.styles.{$group['key']}") : $group['key'],
            'icons' => $group['icons'],
        ], self::$groups ??= self::load());
    }

    /** Präfix der Symbole, die der Tabellen-Assistent vorschlägt (Heroicons, Strichvariante). */
    public const string ASSISTANT_PREFIX = 'heroicon-o-';

    /**
     * Kurznamen der Symbole für den Tabellen-Assistenten, z. B. „users“ für heroicon-o-users.
     *
     * @return list<string>
     */
    public static function assistantNames(): array
    {
        foreach (self::groups() as $group) {
            if ($group['key'] === self::HEROICON_STYLES['o']) {
                return array_values(array_map(static fn (string $icon): string => substr($icon, strlen(self::ASSISTANT_PREFIX)), $group['icons']));
            }
        }

        return [];
    }

    /** Kurzname des Assistenten → vollständiger Name; null, wenn leer oder unbekannt. */
    public static function fromAssistant(mixed $name): ?string
    {
        $name = is_string($name) ? strtolower(trim($name)) : '';

        return $name !== '' && in_array($name, self::assistantNames(), true) ? self::ASSISTANT_PREFIX.$name : null;
    }

    /** Gruppe (Reiter), zu der ein Icon-Name gehört; null, wenn unbekannt. */
    public static function groupOf(?string $icon): ?string
    {
        foreach (self::groups() as $group) {
            if ($icon !== null && in_array($icon, $group['icons'], true)) {
                return $group['key'];
            }
        }

        return null;
    }

    /**
     * @return list<array{key: string, icons: list<string>}>
     */
    private static function load(): array
    {
        $groups = [];

        foreach (app(Factory::class)->all() as $set => $options) {
            if (in_array($set, self::HIDDEN_SETS, true)) {
                continue;
            }

            $names = self::names((array) $options['paths'], (string) $options['prefix']);

            if ($set === 'heroicons') {
                foreach (self::HEROICON_STYLES as $style => $key) {
                    $icons = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, "heroicon-{$style}-")));
                    $groups[] = ['key' => $key, 'icons' => $icons];
                }

                continue;
            }

            $groups[] = ['key' => $set, 'icons' => $names];
        }

        return array_values(array_filter($groups, static fn (array $group): bool => $group['icons'] !== []));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function names(array $paths, string $prefix): array
    {
        $names = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if ($file->getExtension() === 'svg') {
                    $relative = str_replace(DIRECTORY_SEPARATOR, '.', substr($file->getRelativePathname(), 0, -4));
                    $names[] = $prefix.'-'.$relative;
                }
            }
        }

        sort($names);

        return $names;
    }
}
