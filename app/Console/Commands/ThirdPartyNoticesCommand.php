<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Erzeugt THIRD_PARTY_NOTICES.md: alle Komponenten von Drittanbietern, die SAFT im Betrieb nutzt
 * (Composer-Pakete ohne Entwicklung) oder selbst mitliefert (Mermaid-Bundle samt enthaltener
 * Bibliotheken, Schrift Inter), mit Version, Lizenz, Copyright und den Lizenztexten.
 *
 * Läuft auf einem Entwickler-Rechner (braucht node_modules für das Mermaid-Bundle); nach jedem
 * Paket-Update ausführen. Ein Test prüft, dass jedes Composer-Paket aufgeführt ist.
 */
#[Signature('saft:third-party-notices')]
#[Description('Erzeugt THIRD_PARTY_NOTICES.md (Komponenten von Drittanbietern und ihre Lizenzen)')]
class ThirdPartyNoticesCommand extends Command
{
    public const string FILE = 'THIRD_PARTY_NOTICES.md';

    /** Echte Copyright-Zeilen („Copyright (c) 2020 …“, „© …“), nicht „COPYRIGHT HOLDERS BE LIABLE …“ aus dem Lizenztext. */
    private const string COPYRIGHT_LINE = '/^\s*(?:\*\s*)?(?:copyright\s*(?:\(c\)|©|\d{4}|by\b|:)|\(c\)\s*\d{4}|©).*$/mi';

    /** Kopf einer Lizenzdatei mit Paketname und Autor (tc-lib-pdf: „* SOFTWARE : tc-lib-pdf“) – beim Zusammenfassen gleicher Texte ignoriert. */
    private const string HEADER_LINE = '/^\s*\*\s*(?:software|author)\s*:.*$/mi';

    /** Standardtext für Pakete ohne eigene Lizenzdatei (z. B. Filament-Teilpakete; die Datei liegt im Haupt-Repository). */
    private const array STANDARD_TEXTS = [
        'MIT' => "Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated\n"
            ."documentation files (the \"Software\"), to deal in the Software without restriction, including without limitation\n"
            ."the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to\n"
            ."permit persons to whom the Software is furnished to do so, subject to the following conditions:\n\n"
            ."The above copyright notice and this permission notice shall be included in all copies or substantial portions of\n"
            ."the Software.\n\n"
            .'THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE '
            ."WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR\n"
            .'COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR '
            .'OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.',
    ];

    /**
     * Pakete, für die neben ihrer Hauptlizenz eine weitere Lizenz gilt (Lizenzen kombiniert, nicht zur Wahl).
     * Composer meldet das nicht; ein Test verlangt für jedes Produktions-Paket mit mehreren Lizenzdateien
     * eine Entscheidung hier oder in ALTERNATIVE_LICENSE_FILES.
     */
    public const array ADDITIONAL_LICENSES = [
        // Seit 1.0.0 Apache-2.0; die MIT-Lizenz gilt weiter für Beiträge aus der Zeit davor (LICENSE-MIT).
        'anthropic-ai/sdk' => [
            'file' => 'LICENSE-MIT',
            'license' => 'MIT',
            'copyright' => 'Copyright 2023 Anthropic, PBC. (contributions before version 1.0.0)',
            'label' => 'anthropic-ai/sdk (contributions before version 1.0.0)',
        ],
        // LICENSE ist nur der Hinweis „LGPL-3.0“; der Lizenztext selbst steht in COPYING.LESSER (COPYING: die GPL-3.0,
        // auf der die LGPL aufbaut).
        'phpoffice/phpword' => [
            'file' => 'COPYING.LESSER',
            'license' => 'LGPL-3.0-only',
            'copyright' => 'Copyright (c) 2010-2025 PHPWord.',
            'label' => 'phpoffice/phpword (license text)',
        ],
        // Fork von box/spout: Code bis einschließlich Commit cc42c1d (und davon abgeleiteter) steht unter Apache-2.0.
        'openspout/openspout' => [
            'file' => 'LICENSE-for-cc42c1d',
            'license' => 'Apache-2.0',
            'copyright' => 'Copyright (c) Box, Inc. (code up to and descending from commit cc42c1d, from box/spout)',
            'label' => 'openspout/openspout (code from box/spout)',
        ],
    ];

    /** Pakete mit mehreren Lizenzdateien, deren Lizenzen zur Wahl stehen (eine genügt). */
    public const array ALTERNATIVE_LICENSE_FILES = ['chillerlan/php-qrcode'];

    /** Quelle von elkjs (EPL-2.0 verlangt die Angabe, wo der Quelltext erhältlich ist). */
    private const string ELK_SOURCE = 'https://github.com/kieler/elkjs';

    public function handle(): int
    {
        $mermaid = base_path('node_modules/mermaid/package.json');

        if (! File::exists($mermaid)) {
            $this->error('node_modules fehlt (npm ci): die Bibliotheken im Mermaid-Bundle lassen sich sonst nicht ermitteln.');

            return self::FAILURE;
        }

        $composer = $this->composerPackages();
        $bundle = $this->bundledPackages((array) json_decode((string) File::get($mermaid), true));
        $fonts = [[
            'name' => 'Inter (font, shipped with Filament)', 'version' => '4', 'license' => 'OFL-1.1',
            'copyright' => 'Copyright (c) 2016 The Inter Project Authors (https://github.com/rsms/inter)', 'text' => null,
        ], [
            'name' => 'DejaVu Sans (font for PDF documents, resources/fonts/pdf)', 'version' => '2.37', 'license' => 'Bitstream-Vera',
            'copyright' => 'Copyright (c) 2003 by Bitstream, Inc.; DejaVu changes are in public domain', 'text' => null,
        ], [
            'name' => 'Liberation Sans (font for the payment part of QR bills, resources/fonts/pdf)', 'version' => '2.1.5', 'license' => 'OFL-1.1',
            'copyright' => 'Copyright (c) 2012 Red Hat, Inc. with Reserved Font Name Liberation', 'text' => null,
        ]];

        File::put(base_path(self::FILE), $this->render($composer, $bundle, $fonts));
        $this->info(sprintf('%s geschrieben: %d Composer-Pakete, %d Bibliotheken im Mermaid-Bundle.', self::FILE, count($composer), count($bundle)));

        return self::SUCCESS;
    }

    /** @return list<array{name: string, version: string, license: string, copyright: string, text: ?string, extra: list<array{name: string, license: string, text: string}>}> */
    private function composerPackages(): array
    {
        $installed = (array) json_decode((string) File::get(base_path('vendor/composer/installed.json')), true);
        $dev = (array) ($installed['dev-package-names'] ?? []);
        $packages = [];

        foreach ((array) ($installed['packages'] ?? []) as $package) {
            if (in_array($package['name'], $dev, true)) {
                continue;
            }

            $dir = base_path('vendor/composer/'.$package['install-path']);
            $licenses = (array) ($package['license'] ?? ['?']);
            $text = $this->licenseText($dir) ?? self::STANDARD_TEXTS[$licenses[0] ?? ''] ?? null;
            $additional = self::ADDITIONAL_LICENSES[$package['name']] ?? null;
            $extra = [];

            if ($additional !== null) {
                $file = $dir.'/'.$additional['file'];

                if (! is_file($file)) {
                    throw new RuntimeException("{$package['name']}: weitere Lizenzdatei {$additional['file']} fehlt – ADDITIONAL_LICENSES prüfen.");
                }

                $extra[] = [
                    'name' => $additional['label'],
                    'license' => $additional['license'],
                    'text' => trim(str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($file))),
                ];
            }

            $authors = implode(', ', array_filter(array_map(fn (array $a): string => (string) ($a['name'] ?? ''), (array) ($package['authors'] ?? []))));
            $source = preg_replace('/\.git$/', '', (string) ($package['source']['url'] ?? $package['homepage'] ?? ''));
            $license = implode(' OR ', $licenses);
            $copyright = $this->copyright($text) ?? ($authors !== '' ? 'Copyright (c) '.$authors : ($source !== '' ? 'Copyright (c) the authors of '.$source : '–'));
            $packages[] = [
                'name' => (string) $package['name'],
                'version' => ltrim((string) ($package['version'] ?? ''), 'v'),
                // Mehrere Lizenzen zur Wahl (z. B. nette: BSD oder GPL) – SAFT nutzt die erste (BSD). Eine weitere Datei mit
                // derselben Lizenz (nur der volle Text, z. B. PHPWord) und gleiche Copyright-Vermerke erscheinen einmal.
                'license' => $license.($additional !== null && $additional['license'] !== $license ? ' AND '.$additional['license'] : ''),
                'copyright' => $copyright.($additional !== null && $additional['copyright'] !== $copyright ? '; '.$additional['copyright'] : ''),
                'text' => $text,
                'extra' => $extra,
            ];
        }

        usort($packages, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $packages;
    }

    /**
     * Mermaid und alle Bibliotheken, von denen es abhängt (im Bundle enthalten).
     *
     * @param  array<string, mixed>  $mermaid
     * @return list<array{name: string, version: string, license: string, copyright: string, text: ?string}>
     */
    private function bundledPackages(array $mermaid): array
    {
        $seen = [];
        $walk = function (string $name) use (&$walk, &$seen): void {
            if (isset($seen[$name])) {
                return;
            }

            $dir = base_path('node_modules/'.$name);
            $manifest = (array) json_decode((string) @file_get_contents($dir.'/package.json'), true);

            if ($manifest === []) {
                return;
            }

            $text = $this->licenseText($dir);
            $license = $manifest['license'] ?? null;
            $seen[$name] = [
                'name' => $name,
                'version' => (string) ($manifest['version'] ?? ''),
                'license' => is_string($license) ? $license : ($this->guessLicense($text) ?? '?'),
                'copyright' => $this->copyright($text) ?? $this->npmAuthor($manifest),
                'text' => $text,
            ];

            foreach (array_keys((array) ($manifest['dependencies'] ?? [])) as $dependency) {
                $walk((string) $dependency);
            }
        };

        $walk('mermaid');
        ksort($seen);

        return array_values($seen);
    }

    /** @param  array<string, mixed>  $manifest */
    private function npmAuthor(array $manifest): string
    {
        $author = $manifest['author'] ?? null;
        $name = is_array($author) ? (string) ($author['name'] ?? '') : (string) ($author ?? '');

        if ($name !== '') {
            return $name;
        }

        $repository = $manifest['repository'] ?? null;
        $url = is_array($repository) ? (string) ($repository['url'] ?? '') : (string) ($repository ?? '');
        $url = (string) preg_replace(['#^git\+#', '#\.git$#', '#^github:#', '#^git://#'], ['', '', 'https://github.com/', 'https://'], $url);

        return $url === '' ? '–' : 'Copyright (c) the authors of '.$url;
    }

    private function licenseText(string $dir): ?string
    {
        foreach ((array) glob($dir.'/{LICEN[CS]E,licen[cs]e,License,Licence,COPYING}*', GLOB_BRACE) as $file) {
            if (is_file((string) $file)) {
                // Einheitliche Zeilenenden (manche Lizenzdateien kommen mit CRLF).
                return trim(str_replace(["\r\n", "\r"], "\n", (string) file_get_contents((string) $file)));
            }
        }

        return null;
    }

    private function copyright(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        preg_match_all(self::COPYRIGHT_LINE, $text, $m);
        // „* COPYRIGHT : 2002-2026 …“ → „Copyright (c) 2002-2026 …“; Zeilen des Lizenztexts selbst (FSF, Vorlage „<year>“) weglassen.
        $lines = array_map(fn (string $line): string => (string) preg_replace('/^\*\s*copyright\s*:\s*/i', 'Copyright (c) ', trim($line)), $m[0]);
        $lines = array_filter($lines, fn (string $line): bool => ! str_contains($line, 'Free Software Foundation') && ! str_contains($line, '<year>'));
        $lines = array_slice(array_values(array_unique($lines)), 0, 3);

        return $lines === [] ? null : implode('; ', $lines);
    }

    private function guessLicense(?string $text): ?string
    {
        return match (true) {
            $text === null => null,
            str_contains($text, 'MIT License') || str_contains($text, 'Permission is hereby granted, free of charge') => 'MIT',
            str_contains($text, 'ISC License') => 'ISC',
            default => null,
        };
    }

    /**
     * @param  list<array{name: string, version: string, license: string, copyright: string, text: ?string, extra: list<array{name: string, license: string, text: string}>}>  $composer
     * @param  list<array{name: string, version: string, license: string, copyright: string, text: ?string}>  $bundle
     * @param  list<array{name: string, version: string, license: string, copyright: string, text: ?string}>  $fonts
     */
    private function render(array $composer, array $bundle, array $fonts): string
    {
        $table = fn (array $rows): string => "| Component | Version | License | Copyright |\n|---|---|---|---|\n"
            .implode("\n", array_map(fn (array $r): string => sprintf('| %s | %s | %s | %s |', $r['name'], $r['version'], $r['license'], str_replace('|', '\\|', $r['copyright'] ?: '–')), $rows))."\n";

        $elk = collect($bundle)->firstWhere('name', 'elkjs');
        $out = "# Third-party components\n\n"
            ."SAFT uses the third-party components listed below. Each is licensed under its own terms, reproduced in the\n"
            ."section \"License texts\". SAFT's own license (LICENSE) applies only to SAFT's own code and does not restrict the\n"
            ."rights granted by these licenses. Where a component offers a choice of licenses, SAFT uses it under the\n"
            ."permissive option: nette/* under BSD-3-Clause (not GPL), dompurify under Apache-2.0 (not MPL-2.0). Where two\n"
            ."licenses apply together (\"AND\"), both texts are reproduced.\n\n"
            .'*Generated by `php artisan saft:third-party-notices` on '.now()->format('Y-m-d')." – do not edit by hand.*\n\n"
            ."## PHP packages (Composer, production)\n\n"
            ."Installed by Composer during installation (`composer install --no-dev`); most packages also contain their\n"
            ."license file under `vendor/`. Where a package has none (e.g. the Filament sub-packages), the standard text of\n"
            ."its declared license is reproduced below.\n\n".$table($composer)
            ."\n## JavaScript shipped with SAFT\n\n"
            ."`resources/js/vendor/mermaid.min.js` (data model diagram) is a build of Mermaid that contains the libraries below.\n\n"
            .$table($bundle);

        if ($elk !== null) {
            $out .= "\n### Eclipse Public License 2.0 – elkjs\n\n"
                ."The Mermaid build contains elkjs {$elk['version']}, licensed under the Eclipse Public License 2.0\n"
                ."(https://www.eclipse.org/legal/epl-2.0/). elkjs is included unmodified. Its source code is available at\n"
                .self::ELK_SOURCE." (and in the npm package `elkjs@{$elk['version']}`).\n";
        }

        $out .= "\n## Fonts\n\n".$table($fonts)
            ."\nSIL Open Font License 1.1: https://openfontlicense.org/open-font-license-official-text/\n"
            ."Bitstream Vera Fonts License (DejaVu Sans): reproduced in `resources/fonts/pdf/LICENSE-DejaVu.txt`.\n"
            ."SIL Open Font License 1.1 (Liberation Sans): reproduced in `resources/fonts/pdf/LICENSE-LiberationSans.txt`.\n"
            ."\n## License texts\n\nEach distinct license text is reproduced once, followed by the components it applies to.\n";

        // Gleiche Texte (bis auf die Copyright-Zeilen) nur einmal.
        $groups = [];
        $texts = [];
        foreach ([...$composer, ...$bundle] as $row) {
            $texts[] = $row;
            array_push($texts, ...($row['extra'] ?? []));
        }

        foreach ($texts as $row) {
            if ($row['text'] === null) {
                continue;
            }

            $normalized = trim((string) preg_replace(self::COPYRIGHT_LINE, '', $row['text']));
            $key = sha1((string) preg_replace('/\s+/', ' ', (string) preg_replace(self::HEADER_LINE, '', $normalized)));
            $groups[$key] ??= ['license' => $row['license'], 'text' => $normalized, 'names' => []];
            $groups[$key]['names'][] = $row['name'];
        }

        foreach ($groups as $group) {
            $out .= "\n### ".$group['license'].' – '.implode(', ', $group['names'])."\n\n```text\n".$group['text']."\n```\n";
        }

        return $out;
    }
}
