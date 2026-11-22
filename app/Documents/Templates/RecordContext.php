<?php

declare(strict_types=1);

namespace App\Documents\Templates;

use App\DynamicData\DataAccess;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use ArrayAccess;
use Stringable;

/**
 * Ein Datensatz in einer Vorlage (Mustache-Kontext): Felder als FieldValue, Verknüpfungen als weiterer RecordContext
 * ({{kunde_id.ort}}, bis TemplateFields::MAX_DEPTH Schritte), Kind-Tabellen als Liste ({{#positionen}}…{{/positionen}}).
 * Geladen wird erst, was die Vorlage verwendet. Verknüpfte Felder und Kind-Datensätze nur mit Leserecht auf deren
 * Tabelle; ohne Recht bleiben sie leer, die Anzeige einer Verknüpfung ({{kunde_id}}) erscheint trotzdem.
 *
 * Nur die Methoden von ArrayAccess und __toString sind öffentlich – Mustache würde öffentliche Methoden mit dem Namen
 * eines Platzhalters aufrufen.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class RecordContext implements ArrayAccess, Stringable
{
    /** Höchstzahl Kind-Datensätze je Abschnitt. */
    public const int MAX_CHILDREN = 1000;

    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(
        private readonly DynamicRecord $record,
        private readonly CustomTable $table,
        private readonly ?User $user,
        private readonly string $locale,
        private readonly int $depth = 0,
        private readonly ?string $label = null,
    ) {}

    public function __toString(): string
    {
        return (string) $this->label;
    }

    public function offsetExists(mixed $offset): bool
    {
        $name = (string) $offset;

        return TemplateFields::field($this->table, $name) !== null || array_key_exists($name, TemplateFields::children($this->table));
    }

    public function offsetGet(mixed $offset): mixed
    {
        $name = (string) $offset;

        if (! array_key_exists($name, $this->cache)) {
            $field = TemplateFields::field($this->table, $name);
            $this->cache[$name] = $field !== null ? $this->value($field) : $this->children($name);
        }

        return $this->cache[$name];
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    private function value(CustomField $field): mixed
    {
        $raw = $this->record->getAttribute($field->name);
        $type = $field->valueType();

        if ($raw === null || $raw === '' || ($type === FieldType::Boolean && ! $raw)) {
            return $type === FieldType::Boolean ? false : null;
        }

        if ($type !== FieldType::Relation) {
            return new FieldValue($raw, $type, $field->optionsArray(), $this->locale);
        }

        $options = $field->optionsArray();
        $target = TemplateFields::target($field);
        $related = $target === null ? null : DynamicRecord::forTableName($target->name)->newQuery()->withTrashed()->find($raw);

        if ($related === null) {
            return null;
        }

        $label = (string) (RelationDisplay::label($related, $options) ?? '#'.$raw);

        if ($this->depth >= TemplateFields::MAX_DEPTH || ! DataAccess::allows($this->user, $target, 'view')) {
            return new FieldValue($raw, $type, $options, $this->locale, $label);
        }

        return new self($related, $target, $this->user, $this->locale, $this->depth + 1, $label);
    }

    /** @return list<self> */
    private function children(string $name): array
    {
        $field = TemplateFields::children($this->table)[$name] ?? null;
        $child = $field?->customTable;
        $key = $this->record->getKey();

        if ($child === null || $key === null || ! DataAccess::allows($this->user, $child, 'view')) {
            return [];
        }

        $model = DynamicRecord::forTableName($child->name);

        return $model->newQuery()
            ->where($field->name, $key)
            ->orderBy($model->getKeyName())
            ->limit(self::MAX_CHILDREN)
            ->get()
            ->map(fn (DynamicRecord $record): self => new self($record, $child, $this->user, $this->locale, $this->depth + 1))
            ->values()
            ->all();
    }
}
