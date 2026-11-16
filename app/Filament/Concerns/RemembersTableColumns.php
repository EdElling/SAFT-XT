<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\UserTableColumn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Spaltenauswahl einer Filament-Tabelle je Benutzer dauerhaft speichern (Tabelle
 * user_table_columns) statt nur in der Sitzung. Gespeichert werden nur Abweichungen von der
 * Vorgabe als „Spalte => sichtbar“: Neue Spalten erscheinen mit ihrer Vorgabe, entfernte werden
 * ignoriert, „Zurücksetzen“ löscht den Eintrag.
 *
 * Ersetzt loadTableColumnsFromSession() und persistTableColumns() von Filaments HasColumnManager.
 */
trait RemembersTableColumns
{
    /** Schlüssel der gespeicherten Auswahl: Seite bzw. Komponente und Tabellenbezeichner. */
    public function tableColumnsPreferenceKey(): string
    {
        $identifier = $this->getTable()->getIdentifier();

        return Str::limit(static::class.(filled($identifier) ? ':'.$identifier : ''), 191, '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadTableColumnsFromSession(): array
    {
        $default = $this->getDefaultTableColumnState();
        $userId = Auth::id();
        $saved = $userId === null ? null : UserTableColumn::columnsFor((int) $userId, $this->tableColumnsPreferenceKey());

        if ($saved === null) {
            return $default;
        }

        return array_map(static function (array $item) use ($saved): array {
            if (isset($item['columns'])) {
                $item['columns'] = array_map(static fn (array $column): array => self::applySavedToggle($column, $saved), $item['columns']);

                return $item;
            }

            return self::applySavedToggle($item, $saved);
        }, $default);
    }

    protected function persistTableColumns(): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        $default = self::toggleStates($this->getDefaultTableColumnState());
        $deviations = array_filter(
            self::toggleStates($this->tableColumns),
            static fn (bool $visible, string $name): bool => array_key_exists($name, $default) && $default[$name] !== $visible,
            ARRAY_FILTER_USE_BOTH,
        );
        $key = $this->tableColumnsPreferenceKey();

        // Nur schreiben, wenn sich etwas geändert hat (die Methode läuft bei jedem Seitenaufruf).
        if ($deviations !== (UserTableColumn::columnsFor((int) $userId, $key) ?? [])) {
            UserTableColumn::remember((int) $userId, $key, $deviations);
        }
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, bool>  $saved
     * @return array<string, mixed>
     */
    private static function applySavedToggle(array $column, array $saved): array
    {
        if (($column['isToggleable'] ?? false) && array_key_exists((string) $column['name'], $saved)) {
            $column['isToggled'] = $saved[(string) $column['name']];
        }

        return $column;
    }

    /**
     * Sichtbarkeit der umschaltbaren Spalten, auch in Spaltengruppen.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, bool>
     */
    private static function toggleStates(array $items): array
    {
        $states = [];

        foreach ($items as $item) {
            foreach (isset($item['columns']) ? (array) $item['columns'] : [$item] as $column) {
                if ($column['isToggleable'] ?? false) {
                    $states[(string) $column['name']] = (bool) ($column['isToggled'] ?? false);
                }
            }
        }

        ksort($states);

        return $states;
    }
}
