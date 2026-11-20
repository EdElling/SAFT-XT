<?php

declare(strict_types=1);

namespace App\Apps\Definition;

use App\DynamicData\DataMenu;
use App\Filament\Resources\Reports\ReportResource;

/**
 * Objekte einer App-Definition (Tabellenverknüpfungen, Auswertungen, Menüs) als Attribute dieser Installation: Verweise
 * über Namen („tabelle.feld“, Menüpfade, Auswertungsnamen) werden zu IDs. Gemeinsam für Installieren (Etappe 4b) und
 * Aktualisieren (Etappe 6b).
 */
final readonly class DefinitionObjects
{
    public function __construct(private References $refs) {}

    /**
     * @param  array<string, mixed>  $link
     * @return array<string, mixed> Attribute eines ReportLink (ohne uuid)
     */
    public function link(array $link): array
    {
        return [
            'label' => $link['label'],
            'source_table_id' => $this->refs->tableId((string) strstr((string) $link['source'], '.', true)),
            'source_field_id' => $this->refs->fieldId((string) $link['source']),
            'target_table_id' => $this->refs->tableId((string) $link['target_table']),
            'target_field_id' => ($link['target_field'] ?? null) === null ? null : $this->refs->fieldId((string) $link['target_field']),
        ];
    }

    /**
     * Definition einer Auswertung mit Namen → mit IDs dieser Installation.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function reportDefinition(array $definition): array
    {
        $definition['base'] = $this->refs->tableId((string) ($definition['base'] ?? ''));
        $definition['fields'] = array_map(fn (mixed $f): mixed => is_array($f) ? [
            ...$f,
            'path' => array_map(fn (mixed $step): int => str_starts_with((string) $step, 'link:')
                ? -(int) $this->refs->linkId(substr((string) $step, 5))
                : (int) $this->refs->fieldId((string) $step), (array) ($f['path'] ?? [])),
            'field' => (int) $this->refs->fieldId((string) ($f['field'] ?? '')),
        ] : $f, (array) ($definition['fields'] ?? []));

        return $definition;
    }

    /**
     * Ziel eines Menüeintrags.
     *
     * @param  array<string, mixed>  $target
     * @param  array<string, int>  $reports  Name → ID eben angelegter Auswertungen (sonst über den Namen gesucht)
     * @param  array<string, int>  $menus  Pfad → ID eben angelegter Menüs (sonst über den Pfad gesucht)
     * @return array<string, mixed> route, route_parameters, url, view_config
     */
    public function menuTarget(array $target, array $reports = [], array $menus = []): array
    {
        return match ($target['type'] ?? 'group') {
            'data' => ['route' => DataMenu::ROUTE, 'route_parameters' => ['table' => $target['table']], 'url' => null,
                'view_config' => ($columns = array_values(array_filter(array_map(fn (string $f): ?int => $this->refs->fieldId($f), (array) ($target['columns'] ?? []))))) === [] ? null : ['columns' => $columns]],
            'report' => ['route' => ReportResource::VIEW_ROUTE, 'route_parameters' => ['record' => (string) ($reports[(string) $target['report']] ?? $this->refs->reportId((string) $target['report']) ?? 0)], 'url' => null, 'view_config' => null],
            'route' => ['route' => $target['route'], 'route_parameters' => array_map(
                fn (mixed $value): mixed => is_string($value) && str_starts_with($value, 'menu:') ? ($menus[substr($value, 5)] ?? $this->refs->menuId(substr($value, 5))) : $value,
                (array) ($target['parameters'] ?? []),
            ) ?: null, 'url' => null, 'view_config' => null],
            'url' => ['route' => null, 'route_parameters' => null, 'url' => $target['url'], 'view_config' => null],
            default => ['route' => null, 'route_parameters' => null, 'url' => null, 'view_config' => null],
        };
    }

    /** Elternmenü über seinen Pfad (eben angelegt oder vorhanden). */
    public function menuParent(?string $path, array $menus = []): ?int
    {
        return $path === null ? null : ($menus[$path] ?? $this->refs->menuId($path));
    }
}
