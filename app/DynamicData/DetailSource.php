<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\ReportLink;

/**
 * Eine Detailbeziehung (1:n) der Master-Detail-Ansicht: Datensätze der Kindtabelle, deren Feld
 * $column den Wert der Elternspalte trägt. Entweder ein Verknüpfungsfeld (Wert = Schlüssel des
 * Elterndatensatzes) oder eine Tabellenverknüpfung (Wert = Zielspalte, auch über Quellen hinweg).
 *
 * Kennung: Feld-ID bzw. negative ID der Tabellenverknüpfung (wie in Auswertungspfaden) – so stehen beide
 * in detail_field_ids und im Auswahlpfad der URL.
 */
final readonly class DetailSource
{
    public function __construct(
        public int $id,
        public CustomTable $child,
        public CustomField $field,
        public ?ReportLink $link = null,
    ) {}

    public static function ofField(CustomField $field): self
    {
        return new self($field->id, $field->custom_table, $field);
    }

    public static function ofLink(ReportLink $link): self
    {
        return new self(-$link->id, $link->sourceTable, $link->sourceField, $link);
    }

    /** Feld der Kindtabelle, über das gefiltert wird. */
    public function column(): string
    {
        return $this->field->name;
    }

    public function isLink(): bool
    {
        return $this->link !== null;
    }

    /** Wert des Elterndatensatzes, den die Details in column() tragen. */
    public function parentValue(DynamicRecord $parent): mixed
    {
        return $this->link === null ? $parent->getKey() : $parent->getAttribute($this->link->targetColumn());
    }

    public function label(): string
    {
        return $this->link === null
            ? __('data.detail_label', ['table' => $this->child->label, 'field' => $this->field->label])
            : __('data.detail_label_link', ['table' => $this->child->label, 'link' => $this->link->label]);
    }
}
