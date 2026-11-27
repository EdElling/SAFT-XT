<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\UserTableState;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Filter, Sortierung, Suche und Zeilen pro Seite einer Filament-Tabelle je Person dauerhaft speichern (B-027,
 * Tabelle user_table_states) – Filament hält sie nur für die Sitzung. Beim ersten Aufruf der Seite kommt der
 * gespeicherte Stand in die Sitzung, bevor Filament sie liest (ein Stand aus der laufenden Sitzung geht vor); am Ende
 * jeder Anfrage wird der Stand der Sitzung gespeichert, wenn er sich geändert hat (ein Wert in der Sitzung erspart
 * dabei die Abfrage). Nur Abweichungen von der Vorgabe; keine = kein Eintrag.
 *
 * Voraussetzung: Die Tabelle hält Filter, Suche, Spaltensuche und Sortierung in der Sitzung
 * (AppServiceProvider::boot) und die Seite nennt ihren Tabellen-Bezeichner schon vor dem Aufbau.
 *
 * **Vorübergehende Ansicht** (B-170, BULK_EDIT.md §14): Ein Link kann die Tabelle mit einem eigenen Stand öffnen, z. B.
 * gefiltert auf die Datensätze einer Sammeländerung, später Sprunglinks mit Filterdefinition (AI_AGENTS.md V5). Solange
 * sie gilt, wird nichts gespeichert; endTransientTableView() oder ein Aufruf der Seite ohne den Link verwirft den
 * Stand der Sitzung und lädt den gespeicherten neu.
 */
trait RemembersTableState
{
    /** Gilt gerade eine vorübergehende Ansicht? Dann wird der Stand nicht gespeichert. */
    #[Locked]
    public bool $transientTableView = false;

    /** Teile des Stands, mit Filaments Namen in der Sitzung. */
    private static array $rememberedParts = ['filters', 'search', 'column_search', 'sort', 'per_page'];

    /** Bezeichner der Tabelle wie in table()->identifier(), schon vor dem Aufbau der Tabelle bekannt. */
    abstract protected function rememberedTableIdentifier(): string;

    /** Adresse der Seite ohne vorübergehende Ansicht; dorthin führt endTransientTableView(). */
    abstract protected function rememberedTableUrl(): string;

    public function rememberedTableKey(): string
    {
        return Str::limit(static::class.':'.$this->rememberedTableIdentifier(), 191, '');
    }

    /** Wie InteractsWithTable::getTableSessionKey() (SAFT ohne Mandanten), aber ohne aufgebaute Tabelle. */
    private function rememberedSessionKey(string $part): string
    {
        return 'filament.tables.'.hash('sha256', static::class).'.'.hash('sha256', $this->rememberedTableIdentifier()).'.'.$part;
    }

    private function rememberedHashKey(): string
    {
        return 'saft.table_state.'.hash('sha256', $this->rememberedTableKey());
    }

    /** Merker in der Sitzung: Ihr Stand stammt aus einer vorübergehenden Ansicht und gehört nicht der Person. */
    private function transientSessionKey(): string
    {
        return 'saft.table_transient.'.hash('sha256', $this->rememberedTableKey());
    }

    /**
     * Vorübergehende Ansicht beginnen – statt restoreRememberedTableState() aus mount() aufrufen. Filter, Suche und
     * Spaltensuche kommen aus $parts (fehlende sind leer), Sortierung und Zeilen pro Seite aus dem gespeicherten Stand.
     *
     * @param  array<string, mixed>  $parts  Teile wie in der Sitzung, z. B. ['filters' => [...]]
     */
    protected function startTransientTableView(array $parts): void
    {
        $userId = Auth::id();
        $saved = $userId === null ? [] : (UserTableState::stateFor((int) $userId, $this->rememberedTableKey()) ?? []);
        $state = [...$saved, 'filters' => null, 'search' => null, 'column_search' => null, ...$parts];

        foreach (self::$rememberedParts as $part) {
            ($state[$part] ?? null) === null
                ? session()->forget($this->rememberedSessionKey($part))
                : session()->put($this->rememberedSessionKey($part), $state[$part]);
        }

        session()->forget($this->rememberedHashKey());
        session()->put($this->transientSessionKey(), true);
        $this->transientTableView = true;
    }

    /** Vorübergehende Ansicht beenden: Stand der Sitzung verwerfen und die Seite mit dem gespeicherten Stand laden. */
    public function endTransientTableView(): void
    {
        // Der Merker bleibt bis zur Weiterleitung stehen: Sonst speicherte das Ende dieser Anfrage den vorübergehenden
        // Stand über den gemerkten.
        $this->forgetTransientTableState();
        $this->redirect($this->rememberedTableUrl());
    }

    private function forgetTransientTableState(): void
    {
        foreach (self::$rememberedParts as $part) {
            session()->forget($this->rememberedSessionKey($part));
        }

        session()->forget([$this->rememberedHashKey(), $this->transientSessionKey()]);
    }

    /**
     * Gespeicherten Stand in die Sitzung übernehmen – aus mount() der Seite aufrufen, sobald der Bezeichner feststeht;
     * das läuft vor bootedInteractsWithTable(), das die Sitzung liest.
     */
    protected function restoreRememberedTableState(): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        // Stand einer verlassenen vorübergehenden Ansicht gehört nicht der Person.
        if (session()->has($this->transientSessionKey())) {
            $this->forgetTransientTableState();
        }

        foreach (self::$rememberedParts as $part) {
            if (session()->has($this->rememberedSessionKey($part)) && $part !== 'per_page') {
                return; // Stand aus der laufenden Sitzung (z. B. nach „Bearbeiten“ und zurück) geht vor.
            }
        }

        $state = UserTableState::stateFor((int) $userId, $this->rememberedTableKey()) ?? [];

        foreach ($state as $part => $value) {
            if (in_array($part, self::$rememberedParts, true)) {
                session()->put($this->rememberedSessionKey($part), $value);
            }
        }

        session()->put($this->rememberedHashKey(), self::stateHash($state));
    }

    public function dehydrateRemembersTableState(): void
    {
        $userId = Auth::id();

        if ($userId === null || $this->transientTableView) {
            return;
        }

        $state = $this->currentRememberedState();
        $hash = self::stateHash($state);

        if (session()->get($this->rememberedHashKey()) === $hash) {
            return;
        }

        UserTableState::remember((int) $userId, $this->rememberedTableKey(), $state);
        session()->put($this->rememberedHashKey(), $hash);
    }

    /** Weicht die Ansicht von der Vorgabe ab (Filter, Suche, Sortierung, Zeilen pro Seite oder Spalten)? */
    public function hasRememberedTableState(): bool
    {
        return $this->currentRememberedState() !== [] || $this->hasToggledTableColumns();
    }

    /** Sind umschaltbare Spalten anders ein- oder ausgeblendet als vorgegeben (Spaltenauswahl)? */
    private function hasToggledTableColumns(): bool
    {
        $states = static function (array $items): array {
            $result = [];

            foreach ($items as $item) {
                foreach (isset($item['columns']) ? (array) $item['columns'] : [$item] as $column) {
                    if ($column['isToggleable'] ?? false) {
                        $result[(string) $column['name']] = (bool) ($column['isToggled'] ?? false);
                    }
                }
            }

            return $result;
        };

        $default = $states($this->getDefaultTableColumnState());

        foreach ($states((array) $this->tableColumns) as $name => $visible) {
            if (array_key_exists($name, $default) && $default[$name] !== $visible) {
                return true;
            }
        }

        return false;
    }

    /**
     * Knopf „Ansicht zurücksetzen“: Filter, Suche, Sortierung, Zeilen pro Seite und Spaltenauswahl auf die Vorgabe;
     * die gespeicherten Stände werden gelöscht.
     */
    protected function resetTableViewAction(): Action
    {
        return Action::make('resetTableView')
            ->label(__('data.reset_view'))
            ->tooltip(__('data.reset_view_help'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (): bool => $this->transientTableView || $this->hasRememberedTableState())
            ->action(function (): void {
                // In einer vorübergehenden Ansicht zurück zum gespeicherten Stand, nicht zur Vorgabe.
                if ($this->transientTableView) {
                    $this->endTransientTableView();

                    return;
                }

                $this->resetTableFiltersForm();
                $this->resetTableSearch();
                $this->resetTableColumnSearches();
                $this->tableSort = null;
                $this->updatedTableSort();
                $this->tableRecordsPerPage = $this->getTable()->getDefaultPaginationPageOption();
                $this->updatedTableRecordsPerPage();
                // Wie „Zurücksetzen“ im Spaltenmenü; die gespeicherte Spaltenauswahl wird dabei gelöscht.
                $this->resetTableColumnManager();
            });
    }

    /**
     * Stand der Tabelle ohne Vorgaben, aus den Eigenschaften der Komponente (in derselben Form, die Filament in die
     * Sitzung legt). Nicht aus der Sitzung: Eine gleichzeitige Anfrage, z. B. ein Widget des Dashboards nach der
     * Anmeldung, kann ihren älteren Stand der Sitzung zurückschreiben und die Werte der Tabelle hinauswerfen; die Liste
     * zeigte dann die Suche weiter, aber ohne „Ansicht zurücksetzen“, und am Ende der Anfrage würde der leere Stand
     * gespeichert (LIS-19 auf GitHub, 27.11.2026).
     *
     * @return array<string, mixed>
     */
    private function currentRememberedState(): array
    {
        $values = [
            'filters' => $this->tableFilters,
            'search' => $this->tableSearch,
            'column_search' => $this->tableColumnSearches,
            'sort' => $this->tableSort,
            'per_page' => $this->tableRecordsPerPage,
        ];
        $state = [];

        foreach (self::$rememberedParts as $part) {
            $value = $values[$part];
            $isDefault = match ($part) {
                'filters', 'column_search' => self::isEmptyState($value),
                'search', 'sort' => blank($value),
                'per_page' => $value === null || (string) $value === (string) $this->getTable()->getDefaultPaginationPageOption(),
            };

            if (! $isDefault) {
                $state[$part] = $value;
            }
        }

        return $state;
    }

    /** Filterstand ohne gesetzten Wert (null, leer, false) – so wie nach „Zurücksetzen“. */
    private static function isEmptyState(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::isEmptyState($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || $value === '' || $value === false;
    }

    /** @param  array<string, mixed>  $state */
    private static function stateHash(array $state): string
    {
        return hash('sha256', (string) json_encode($state));
    }
}
