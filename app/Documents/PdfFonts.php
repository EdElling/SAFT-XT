<?php

declare(strict_types=1);

namespace App\Documents;

use Com\Tecnick\Pdf\Font\Import;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Schriften für tc-lib-pdf. Die Standardschrift DejaVu Sans liegt umgewandelt in resources/fonts/pdf; eine
 * hochgeladene Hausschrift (TTF) wird beim ersten Gebrauch umgewandelt und unter storage/framework/cache/pdf-fonts
 * zwischengespeichert – je Satz Dateien ein Verzeichnis, eine geänderte Datei ergibt ein neues.
 */
final class PdfFonts
{
    public const string DEFAULT = 'dejavusans';

    public const string HOUSE = 'hausschrift';

    /** Schnitte und ihre Endung im Schriftnamen (tc-lib-pdf sucht „hausschriftb“ für fett usw.). */
    private const array STYLES = ['' => 'regular', 'b' => 'bold', 'i' => 'italic', 'bi' => 'bold'];

    public static function directory(): string
    {
        return resource_path('fonts/pdf');
    }

    public static function cacheDirectory(): string
    {
        return storage_path('framework/cache/pdf-fonts');
    }

    /** tc-lib-pdf liest Schriften ohne eigenen Pfad aus K_PATH_FONTS. */
    public static function boot(): void
    {
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', self::directory().'/');
        }
    }

    /**
     * Umgewandelte Hausschrift: Verzeichnis mit hausschrift.json, hausschriftb.json, hausschrifti.json und
     * hausschriftbi.json. Fehlt ein Schnitt, wird der normale (bzw. für fett-kursiv der fette) verwendet.
     *
     * @param  array{regular: string|null, bold: string|null, italic: string|null}  $fonts
     */
    public static function house(array $fonts): ?string
    {
        if ($fonts['regular'] === null) {
            return null;
        }

        $files = array_map(fn (?string $file): ?string => $file !== null && is_file($file) ? $file : null, $fonts);
        $key = md5(implode('|', array_map(fn (?string $file): string => $file === null ? '' : $file.'@'.filemtime($file), $files)));
        $directory = self::cacheDirectory().'/'.$key;

        if (is_file($directory.'/'.self::HOUSE.'bi.json')) {
            return $directory;
        }

        File::ensureDirectoryExists($directory);

        foreach (self::STYLES as $suffix => $style) {
            $source = $files[$style] ?? $files['regular'];

            if ($suffix === 'bi' && $files['bold'] === null) {
                $source = $files['regular'];
            }

            self::convert((string) $source, $directory, self::HOUSE.$suffix);
        }

        return $directory;
    }

    /** Lässt sich die Datei als TrueType-Schrift umwandeln? */
    public static function isConvertible(string $file): bool
    {
        $directory = self::cacheDirectory().'/check-'.Str::random(12);

        try {
            File::ensureDirectoryExists($directory);
            self::convert($file, $directory, 'probe');

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /** Wandelt eine TTF-Datei um und legt sie unter dem gewünschten Namen ab (name.json, name.z, name.ctg.z). */
    private static function convert(string $source, string $directory, string $name): void
    {
        $temporary = $directory.'/tmp-'.Str::random(8);
        File::ensureDirectoryExists($temporary);

        try {
            $import = new Import($source, $temporary.'/', 'TrueTypeUnicode', '', 32, 3, 10);
            $original = $import->getFontName();
            $definition = json_decode((string) file_get_contents($temporary.'/'.$original.'.json'), true, 512, JSON_THROW_ON_ERROR);

            foreach (['file' => '.z', 'ctg' => '.ctg.z'] as $field => $extension) {
                if (is_file($temporary.'/'.$original.$extension)) {
                    File::move($temporary.'/'.$original.$extension, $directory.'/'.$name.$extension);
                    $definition[$field] = $name.$extension;
                }
            }

            File::put($directory.'/'.$name.'.json', json_encode($definition, JSON_THROW_ON_ERROR));
        } finally {
            File::deleteDirectory($temporary);
        }
    }
}
