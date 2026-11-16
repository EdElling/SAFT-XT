<?php

declare(strict_types=1);

namespace Tests\Feature;

use RecursiveArrayIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Deutsche Oberfläche und Handbücher in Schweizer Rechtschreibung: kein „ß“. Filament-Texte werden über den
 * Übersetzer geprüft, also nach den Überschreibungen in lang/vendor/<paket>/de (siehe dort).
 */
class SwissSpellingTest extends TestCase
{
    /** Filament-Pakete und ihr Übersetzungs-Namensraum. */
    private const array FILAMENT = [
        'actions' => 'filament-actions', 'filament' => 'filament-panels', 'forms' => 'filament-forms',
        'infolists' => 'filament-infolists', 'notifications' => 'filament-notifications', 'query-builder' => 'filament-query-builder',
        'schemas' => 'filament-schemas', 'support' => 'filament', 'tables' => 'filament-tables', 'widgets' => 'filament-widgets',
    ];

    /** @return array<string, string> Schlüssel => Text */
    private function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($lines));

        foreach ($iterator as $value) {
            $keys = [];

            foreach (range(0, $iterator->getDepth()) as $depth) {
                $keys[] = $iterator->getSubIterator($depth)->key();
            }

            if (is_string($value)) {
                $flat[$prefix.implode('.', $keys)] = $value;
            }
        }

        return $flat;
    }

    public function test_own_german_texts_use_ss(): void
    {
        $found = [];

        foreach (glob(lang_path('de/*.php')) ?: [] as $file) {
            foreach ($this->flatten(include $file, basename($file, '.php').'.') as $key => $text) {
                if (str_contains($text, 'ß')) {
                    $found[] = "{$key}: {$text}";
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function test_filament_german_texts_use_ss_after_overrides(): void
    {
        app()->setLocale('de');
        $found = [];

        foreach (self::FILAMENT as $package => $namespace) {
            $directory = base_path("vendor/filament/{$package}/resources/lang/de/");

            if (! is_dir($directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $group = substr((string) preg_replace('/\.php$/', '', $file->getPathname()), strlen($directory));

                foreach (array_keys(array_filter($this->flatten(include $file->getPathname()), fn (string $t): bool => str_contains($t, 'ß'))) as $key) {
                    $text = __("{$namespace}::{$group}.{$key}");

                    if (str_contains((string) $text, 'ß')) {
                        $found[] = "{$namespace}::{$group}.{$key}";
                    }
                }
            }
        }

        $this->assertSame([], $found, 'Neue Filament-Texte mit ß: in lang/vendor/<namespace>/de überschreiben.');
    }

    public function test_german_manuals_use_ss(): void
    {
        $found = array_values(array_filter(
            [...glob(base_path('docs/manual/*.de.md')) ?: [], base_path('README.de.md')],
            fn (string $file): bool => str_contains((string) file_get_contents($file), 'ß'),
        ));

        $this->assertSame([], array_map('basename', $found));
    }
}
