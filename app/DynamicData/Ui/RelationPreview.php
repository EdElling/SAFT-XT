<?php

declare(strict_types=1);

namespace App\DynamicData\Ui;

use App\DynamicData\DataAccess;
use App\DynamicData\DataMenu;
use App\DynamicData\RelationDisplay;
use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Closure;
use Filament\Actions\Action;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;

/**
 * Vorschau des verknüpften Datensatzes (n:1, z. B. Artikel einer Bestellposition) in
 * einem Slide-over – ohne die Seite zu verlassen. Nur lesend; Link zur Detailseite.
 * Ohne Leserecht auf die Zieltabelle gibt es keine Aktion (reiner Text).
 */
final class RelationPreview
{
    /**
     * @param  Closure  $parentId  von Filament ausgewertet, liefert die ID des verknüpften Datensatzes
     */
    public static function make(CustomField $relationField, Closure $parentId, string $name): ?Action
    {
        $options = $relationField->optionsArray();
        $target = once(fn (): ?CustomTable => CustomTable::query()->with('fields')->where('name', (string) ($options['table'] ?? ''))->first());

        if ($target === null || ! DataAccess::current($target, 'view')) {
            return null;
        }

        // Schlüssel wie in der Datenbank: Zahl, Text oder UUID.
        $id = static fn (Action $action): int|string|null => ($value = $action->evaluate($parentId)) === null || $value === '' ? null : $value;

        return Action::make($name)
            ->label(__('data.preview.show', ['field' => $relationField->label]))
            ->icon('heroicon-o-eye')
            ->slideOver()
            ->visible(fn (Action $action): bool => $id($action) !== null)
            ->modalHeading(function (Action $action) use ($target, $id, $options): string {
                $record = self::parent($target, $id($action));
                $display = RelationDisplay::label($record, $options) ?? '#'.$id($action);

                return $target->label.': '.$display;
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('common.close'))
            ->schema(fn (Action $action): array => self::content($target, self::parent($target, $id($action))))
            ->extraModalFooterActions(fn (Action $action): array => self::parent($target, $id($action)) === null ? [] : [
                Action::make($name.'_open')
                    ->label(__('data.preview.open', ['field' => $relationField->label]))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(DynamicDataPage::urlFor($target->name, $target->hasSingleKey() ? $id($action) : null, DataMenu::firstMenuIdFor($target->name))),
            ]);
    }

    /**
     * @return list<Component>
     */
    private static function content(CustomTable $target, ?DynamicRecord $record): array
    {
        if ($record === null) {
            return [Callout::make(__('data.preview.unavailable'))->warning()];
        }

        return [
            Callout::make(__('data.record_deleted'))->danger()->visible($record->trashed()),
            // Slide-in: eine einzige Liste „Name | Wert“ wie im Fenster „Details“.
            RecordEntries::blocks($target, $record, maxBlocks: 1),
        ];
    }

    /** Gelöschte Datensätze nur mit Löschrecht (wie in Liste und Detailseite). */
    private static function parent(CustomTable $target, int|string|null $id): ?DynamicRecord
    {
        if ($id === null) {
            return null;
        }

        // Teil des Cache-Schlüssels (once() berücksichtigt genutzte Variablen): Ergebnis
        // hängt vom Löschrecht des aktuellen Benutzers ab.
        $withTrashed = DataAccess::current($target, 'delete');
        $userId = auth()->id();

        return once(fn (): ?DynamicRecord => $userId === auth()->id()
            ? DynamicRecord::forTable($target)->newQuery()->when($withTrashed, fn ($q) => $q->withTrashed())->find($id)
            : null);
    }
}
