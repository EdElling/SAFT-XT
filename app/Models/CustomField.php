<?php

declare(strict_types=1);

namespace App\Models;

use App\DynamicData\Schema\FieldType;
use App\Models\Base\CustomField as BaseCustomField;
use App\Models\Concerns\HasAuditLog;
use App\Models\Concerns\HasStableKey;
use App\Models\Concerns\HasTranslatedAttributes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadaten einer Spalte einer Designer-Tabelle.
 * Schreibende Änderungen ausschließlich über App\Services\DynamicSchemaService.
 */
class CustomField extends BaseCustomField
{
    use HasAuditLog;
    use HasStableKey;
    use HasTranslatedAttributes;

    /** Je Sprache (JSON); fehlt eine, gilt die Vorgabesprache. */
    public array $translatable = ['label'];

    protected $attributes = [
        'is_required' => false,
        'is_unique' => false,
        'is_searchable' => false,
        'is_sortable' => false,
        'show_in_table' => true,
        'show_in_form' => true,
        'is_active' => true,
        'is_read_only' => false,
        'is_generated' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FieldType::class,
        ];
    }

    /** Nicht "table()": kollidiert mit Eloquents interner Eigenschaft $table. */
    public function customTable(): BelongsTo
    {
        return $this->custom_table();
    }

    /**
     * @return array<string, mixed>
     */
    public function optionsArray(): array
    {
        return (array) ($this->options ?? []);
    }

    /** Feld-Option: Ganzzahl ohne Tausendertrennzeichen anzeigen (Jahre, Nummern). */
    public const string GROUP_DIGITS = 'group_digits';

    /** Ganzzahl mit Tausendertrennzeichen anzeigen? Vorgabe ja; „Jahr“ u. ä. ohne. */
    public function groupsDigits(): bool
    {
        return ($this->optionsArray()[self::GROUP_DIGITS] ?? true) !== false;
    }

    /** Typ des gespeicherten Werts (berechnete Felder: Ganz- oder Dezimalzahl). */
    public function valueType(): FieldType
    {
        return $this->type->valueType($this->optionsArray());
    }

    /** Stabile, umbenennungssichere Namen für Indizes/Fremdschlüssel (max. 63 Zeichen). */
    public function indexName(string $suffix): string
    {
        return "cf_{$this->getKey()}_{$suffix}";
    }

    /** Spalte braucht einen Index (Sortierung, Verknüpfung). */
    public function needsIndex(): bool
    {
        return $this->type === FieldType::Relation || $this->is_sortable;
    }
}
