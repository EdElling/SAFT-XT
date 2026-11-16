<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Schema\IdentifierValidator;
use App\Models\CustomTable;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * Technische Tabellennamen sind systemweit eindeutig (Table-Designer, Import, Table-Connector teilen
 * eine Liste). Die Regel prüft schon beim Verlassen des Felds und nennt, wem der Name gehört.
 */
final class TableNameRule
{
    /**
     * Feld live prüfen: beim Verlassen validieren, Meldung mit Eigentümer.
     *
     * @param  bool  $physical  auch Tabellen im Namensraum ohne Metadaten (Designer, Import)
     */
    public static function apply(TextInput $input, bool $physical): TextInput
    {
        return $input
            ->live(onBlur: true)
            ->afterStateUpdated(function (Component $component): void {
                $component->getLivewire()->validateOnly($component->getStatePath());
            })
            ->rule(fn (?CustomTable $record = null): Closure => self::rule($record, $physical));
    }

    public static function rule(?CustomTable $ignore, bool $physical): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore, $physical): void {
            // Ungültige Namen meldet die Formatregel; nur gültige nachschlagen.
            $message = is_string($value) && preg_match(IdentifierValidator::PATTERN, $value) === 1 ? self::takenBy($value, $ignore, $physical) : null;

            if ($message !== null) {
                $fail($message);
            }
        };
    }

    /** Meldung, wenn der Name vergeben ist, sonst null. */
    public static function takenBy(string $name, ?CustomTable $ignore = null, bool $physical = true): ?string
    {
        $table = CustomTable::query()->with('databaseConnection')->where('name', $name)
            ->when($ignore?->exists, fn ($q) => $q->whereKeyNot($ignore->getKey()))
            ->first();

        if ($table !== null) {
            return __('tables.errors.name_taken_by', [
                'name' => $name,
                'owner' => $table->isExternal()
                    ? __('tables.errors.owner_external', ['label' => $table->label, 'connection' => (string) $table->databaseConnection?->name])
                    : __('tables.errors.owner_designer', ['label' => $table->label]),
            ]);
        }

        $namespace = app(DynamicDataNamespace::class);

        if ($physical && $ignore === null && $namespace->connection()->getSchemaBuilder()->hasTable($namespace->qualify($name))) {
            return __('tables.errors.table_without_metadata', ['name' => $name]);
        }

        return null;
    }
}
