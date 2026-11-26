<?php

declare(strict_types=1);

namespace App\DynamicData\Schema;

use App\DynamicData\Numbering\NumberPattern;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * Feldtypen des Table-Designers und ihre portable Abbildung auf den Schema Builder.
 * Alle Spalten sind in der Datenbank nullable; Pflichtfelder prüft die Anwendung
 * (sonst scheitert das Hinzufügen von Pflichtfeldern zu befüllten Tabellen).
 */
enum FieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Select = 'select';
    case Relation = 'relation';
    case Computed = 'computed';
    // Dateien (B-021): im Datensatz je Datei Kennung und Name (JSON), Datei und Angaben in record_files.
    case File = 'file';
    // Nummernkreis (B-135): formatierte Nummer als Text, von SAFT beim Anlegen vergeben, nie änderbar.
    case Number = 'number';

    public const int SELECT_LENGTH = 255;

    public function label(): string
    {
        return __("tables.types.{$this->value}");
    }

    /**
     * @param  array<string, mixed>  $options  bereits normalisiert (FieldDefinitionNormalizer)
     */
    public function addColumn(Blueprint $table, string $column, array $options): ColumnDefinition
    {
        $definition = match ($this) {
            self::String => $table->string($column, (int) $options['length']),
            self::Text => $table->text($column),
            self::Integer => $table->bigInteger($column),
            self::Decimal => $table->decimal($column, (int) $options['precision'], (int) $options['scale']),
            self::Boolean => $table->boolean($column),
            self::Date => $table->date($column),
            self::DateTime => $table->dateTime($column),
            self::Select => $table->string($column, self::SELECT_LENGTH),
            self::Relation => $table->unsignedBigInteger($column),
            self::File => $table->json($column),
            self::Number => $table->string($column, NumberPattern::COLUMN_LENGTH),
            // Berechnete Felder speichern als Ganz- oder Dezimalzahl (Option „result“).
            self::Computed => $this->valueType($options)->addColumn($table, $column, $options),
        };

        return $definition->nullable();
    }

    /**
     * Eloquent-Cast für DynamicRecord.
     *
     * @param  array<string, mixed>  $options
     */
    public function cast(array $options): ?string
    {
        return match ($this) {
            self::Computed => $this->valueType($options)->cast($options),
            self::Integer, self::Relation => 'integer',
            self::Decimal => 'decimal:'.(int) ($options['scale'] ?? 2),
            self::Boolean => 'boolean',
            self::Date => 'date',
            self::DateTime => 'datetime',
            self::File => 'array',
            default => null,
        };
    }

    /**
     * Typ des gespeicherten Werts: berechnete Felder sind Ganz- oder Dezimalzahlen (Option „result“), Nummernkreise
     * Text, alle anderen sind sie selbst. Anzeige, Filter, Export und Regeln richten sich danach.
     *
     * @param  array<string, mixed>  $options
     */
    public function valueType(array $options): self
    {
        return match ($this) {
            self::Computed => ($options['result'] ?? 'decimal') === 'integer' ? self::Integer : self::Decimal,
            self::Number => self::String,
            default => $this,
        };
    }

    /** Werte, die SAFT selbst erzeugt: nie aus Formular, Import-Zuordnung oder Sammeländerung. */
    public function isGenerated(): bool
    {
        return $this === self::Computed || $this === self::Number;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t): array => [$t->value => $t->label()])->all();
    }
}
