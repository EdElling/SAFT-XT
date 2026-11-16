<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

use App\DynamicData\Schema\IdentifierValidator;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomTable;
use Illuminate\Support\Str;

/**
 * Leitet aus Spaltentiteln und Dateinamen technische Namen ab: "Straße & Nr." -> strasse_nr.
 * Systemspalten und reservierte Wörter erhalten die Endung _import.
 */
final class ColumnNameDeriver
{
    public const string SUFFIX = '_import';

    private const int MAX_LENGTH = 63;

    public function __construct(private readonly IdentifierValidator $identifiers) {}

    /**
     * Feldname und Hinweis-Code (renamed_system, renamed_reserved, name_shortened) oder null,
     * wenn sich aus dem Titel kein Name bilden lässt.
     *
     * @return array{name: string, note: string|null}|null
     */
    public function column(string $title): ?array
    {
        $name = $this->slug($title, 'f_');

        if ($name === null) {
            return null;
        }

        $note = mb_strlen($this->slug($title, 'f_', shorten: false) ?? '') > self::MAX_LENGTH ? 'name_shortened' : null;

        if ($this->validColumn($name)) {
            return ['name' => $name, 'note' => $note];
        }

        $renamed = $this->withSuffix($name);

        if (! $this->validColumn($renamed)) {
            return null;
        }

        return ['name' => $renamed, 'note' => in_array($name, IdentifierValidator::SYSTEM_COLUMNS, true) ? 'renamed_system' : 'renamed_reserved'];
    }

    /** Tabellenname aus dem Dateinamen, eindeutig unter den vorhandenen Tabellen. */
    public function table(string $fileName): string
    {
        $base = $this->slug(pathinfo($fileName, PATHINFO_FILENAME), 't_') ?? 'import';

        if (! $this->validTable($base)) {
            $base = $this->validTable($suffixed = $this->withSuffix($base)) ? $suffixed : 'import';
        }

        $existing = CustomTable::query()->where('name', 'like', $base.'%')->pluck('name')->all();
        $name = $base;

        for ($n = 2; in_array($name, $existing, true); $n++) {
            $name = mb_substr($base, 0, self::MAX_LENGTH - strlen('_'.$n)).'_'.$n;
        }

        return $name;
    }

    private function slug(string $title, string $digitPrefix, bool $shorten = true): ?string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(Str::ascii($title, 'de'))), '_');

        if ($slug === '') {
            return null;
        }

        // Namen beginnen mit einem Buchstaben: "2024" -> f_2024.
        if (ctype_digit($slug[0])) {
            $slug = $digitPrefix.$slug;
        }

        return $shorten ? rtrim(mb_substr($slug, 0, self::MAX_LENGTH), '_') : $slug;
    }

    private function withSuffix(string $name): string
    {
        return rtrim(mb_substr($name, 0, self::MAX_LENGTH - strlen(self::SUFFIX)), '_').self::SUFFIX;
    }

    private function validColumn(string $name): bool
    {
        try {
            $this->identifiers->assertValidColumnName($name);

            return true;
        } catch (BusinessRuleException) {
            return false;
        }
    }

    private function validTable(string $name): bool
    {
        try {
            $this->identifiers->assertValidTableName($name);

            return true;
        } catch (BusinessRuleException) {
            return false;
        }
    }
}
