<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\Models\User;

/**
 * Namen der Benutzer für die Systemspalten „angelegt von“ / „geändert von“ (B-159). Die Benutzer liegen auf der
 * Hauptverbindung, die Datensätze in app_data; eine Unterabfrage über beide wäre nicht portabel. Deshalb einmal je
 * Anfrage alle Namen laden (kleine Installationen: wenige hundert Benutzer), erst wenn eine Spalte sie braucht.
 * Scoped im Container: je Anfrage bzw. Arbeitsschritt neu.
 */
final class UserNames
{
    /** @var array<int, string>|null */
    private ?array $names = null;

    /** Name zur ID; ein gelöschter Benutzer als „#5“, ohne ID null. */
    public function name(mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->all()[(int) $id] ?? '#'.(int) $id;
    }

    /** @return array<int, string> ID => Name, nach Name sortiert (Auswahl im Filter) */
    public function all(): array
    {
        if ($this->names === null) {
            $this->names = User::query()->orderBy('name')->pluck('name', 'id')->map(static fn (mixed $name): string => (string) $name)->all();
        }

        return $this->names;
    }
}
