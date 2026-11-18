<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DataModelPage;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Navigation\MenuTree;
use Illuminate\Database\Eloquent\Collection;

/**
 * Menüeinträge für Datentabellen (Designer- und angebundene Tabellen, Route mit Parameter {table}),
 * standardmäßig in der Gruppe „Daten“, auf Wunsch in einer anderen Gruppe oder Untergruppe.
 */
final class DataMenu
{
    public const string ROUTE = 'filament.admin.pages.data';

    public const string GROUP_LABEL = 'Daten';

    /** Einrückung je Ebene in der Gruppenauswahl (geschützte Leerzeichen, sonst kürzt der Browser sie). */
    private const string INDENT = "\u{00A0}\u{00A0}\u{00A0}\u{00A0}";

    /** @var array<string, string> Bezeichnung der Gruppe in den übrigen Sprachen */
    public const array GROUP_LABELS = ['en' => 'Data', 'fr' => 'Données', 'it' => 'Dati'];

    /**
     * Ansicht für Links ins Datenmodul: der erste aktive Menüeintrag der Tabelle, sofern er eine
     * eigene Spaltenauswahl hat. Ohne sie wäre ?view= falsch, der Eintrag gälte nicht als aktiv.
     */
    /**
     * Menügruppe des ersten Menüeintrags je Tabelle als Pfad, z. B. „Daten › Forschung“ (Rollen, Entwickler-Kontext).
     *
     * @return array<string, string> Tabellenname => Pfad
     */
    public static function groupPaths(): array
    {
        $groups = [];

        foreach (Menu::query()->where('route', self::ROUTE)->with('parent.parent')->orderBy('id')->get() as $entry) {
            $table = (string) ($entry->route_parameters['table'] ?? '');

            if ($table === '' || isset($groups[$table]) || $entry->parent === null) {
                continue;
            }

            $groups[$table] = implode(' › ', array_filter([(string) $entry->parent->parent?->label, (string) $entry->parent->label]));
        }

        return $groups;
    }

    public static function firstMenuIdFor(string $table): ?int
    {
        return once(function () use ($table): ?int {
            $menu = Menu::query()
                ->where('route', self::ROUTE)
                ->where('route_parameters->table', $table)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->first();

            return empty($menu?->view_config) ? null : $menu->id;
        });
    }

    public function exists(CustomTable $table): bool
    {
        return $this->entries($table)->isNotEmpty();
    }

    /** Auswahl „Gruppe ‚Daten‘ anlegen“, solange es sie noch nicht gibt. */
    public const string NEW_DATA_GROUP = 'new-data';

    /** Legt den ersten Eintrag der Tabelle unter „Daten“ an; gibt es schon einen, bleibt es dabei. */
    public function ensure(CustomTable $table): Menu
    {
        /** @var Menu|null $existing */
        $existing = $this->entries($table)->first();

        return $existing ?? $this->create($table, self::NEW_DATA_GROUP);
    }

    /**
     * Legt einen (weiteren) Eintrag der Tabelle unter der gewählten Gruppe an.
     *
     * @param  int|string  $group  ID einer Gruppe aus groupOptions() oder NEW_DATA_GROUP
     */
    public function create(CustomTable $table, int|string $group): Menu
    {
        $parent = $group === self::NEW_DATA_GROUP ? $this->dataGroup($table->app_id) : Menu::query()->find((int) $group);

        // Nur Gruppen der App der Tabelle (B-107).
        if ($parent === null || ! array_key_exists((string) $parent->id, $this->groupOptions($table->app_id))) {
            throw new BusinessRuleException(__('menus.form.invalid_group'), 'menu.invalid_group');
        }

        return Menu::create([
            'parent_id' => $parent->id,
            'label' => $table->getTranslations('label'),
            'route' => self::ROUTE,
            'route_parameters' => ['table' => $table->name],
            'icon' => $table->icon,
            'permission_name' => DataPermissions::name($table->name, 'view'),
            'sort_order' => ((int) Menu::query()->where('parent_id', $parent->id)->max('sort_order')) + 10,
            'is_active' => $table->is_active,
        ]);
    }

    /**
     * Gruppen, unter die ein Eintrag passt (höchstens MenuTree::MAX_DEPTH Ebenen), in Menüreihenfolge;
     * Untergruppen eingerückt mit ihrem Pfad. Fehlt „Daten“, steht „Daten (wird angelegt)“ zur Wahl.
     *
     * @return array<string, string>
     */
    public function groupOptions(?int $appId = null): array
    {
        // Mit App: nur ihre Gruppen (B-107); System-Gruppen wie die Administration gehören zu keiner App.
        $groups = Menu::query()->whereNull('route')->whereNull('url')
            ->when($appId !== null, static fn ($q) => $q->where('app_id', $appId))
            ->orderBy('sort_order')->orderedByLabel()->get(['id', 'parent_id', 'label', 'sort_order']);
        $children = $groups->groupBy(fn (Menu $menu): string => (string) ($menu->parent_id ?? 'root'));
        $options = [];

        $walk = function (string $parent, array $path) use (&$walk, &$options, $children): void {
            foreach ($children[$parent] ?? [] as $group) {
                /** @var Menu $group */
                $labels = [...$path, (string) $group->label];

                // Der Eintrag selbst liegt eine Ebene tiefer als die Gruppe.
                if (count($labels) < MenuTree::MAX_DEPTH) {
                    $options[(string) $group->id] = str_repeat(self::INDENT, count($path)).implode(' › ', $labels);
                    $walk((string) $group->id, $labels);
                }
            }
        };
        $walk('root', []);

        if ($this->findDataGroup($appId) === null) {
            // Vereinigung statt Spread: Spread nummeriert die Gruppen-IDs (numerische Schlüssel) neu.
            $options = [self::NEW_DATA_GROUP => __('menus.form.data_group_new', ['group' => __('menus.form.data_group')])] + $options;
        }

        return $options;
    }

    /** Vorbelegung der Auswahl: die Gruppe „Daten“ (oder ihr Anlegen). */
    public function defaultGroup(?int $appId = null): string
    {
        return (string) ($this->findDataGroup($appId)?->id ?? self::NEW_DATA_GROUP);
    }

    /** Gruppe „Daten“ – mit App die dieser App (B-107). */
    private function findDataGroup(?int $appId = null): ?Menu
    {
        return Menu::query()->whereNull('parent_id')->whereNull('route')->whereNull('url')->where('label->de', self::GROUP_LABEL)
            ->when($appId !== null, static fn ($q) => $q->where('app_id', $appId))
            ->first();
    }

    private function dataGroup(?int $appId = null): Menu
    {
        return $this->findDataGroup($appId)
            ?? Menu::create(['app_id' => $appId, 'label' => [...self::GROUP_LABELS, 'de' => self::GROUP_LABEL], 'sort_order' => 500, 'is_active' => true]);
    }

    /** Bezeichnung des Menüeintrags „Datenmodell“ je Sprache (Demo-Daten). */
    public const array DATA_MODEL_LABELS = ['de' => 'Datenmodell', 'en' => 'Data model', 'fr' => 'Modèle de données', 'it' => 'Modello dati'];

    /**
     * Legt in der Gruppe einen Eintrag „Datenmodell“ am Ende an, falls sie noch keinen hat
     * (wiederholbar, z. B. für die Demo-Daten).
     */
    public function ensureDataModel(Menu $group): Menu
    {
        /** @var Menu|null $existing */
        $existing = Menu::query()->where('parent_id', $group->id)->where('route', DataModelPage::ROUTE)->first();

        return $existing ?? Menu::create([
            'parent_id' => $group->id,
            'label' => self::DATA_MODEL_LABELS,
            'route' => DataModelPage::ROUTE,
            'icon' => 'heroicon-o-share',
            'sort_order' => ((int) Menu::query()->where('parent_id', $group->id)->max('sort_order')) + 10,
            'is_active' => true,
        ]);
    }

    /** Anzeigename, Icon und Aktiv-Status folgen der Tabelle. */
    public function sync(CustomTable $table): void
    {
        $this->entries($table)->each(fn (Menu $menu) => $menu->update([
            'label' => $table->getTranslations('label'),
            'icon' => $table->icon,
            'is_active' => $table->is_active,
        ]));
    }

    public function remove(CustomTable $table): void
    {
        $this->entries($table)->each->delete();
    }

    /**
     * @return Collection<int, Menu>
     */
    private function entries(CustomTable $table): Collection
    {
        return Menu::query()
            ->where('route', self::ROUTE)
            ->where('route_parameters->table', $table->name)
            ->get();
    }
}
