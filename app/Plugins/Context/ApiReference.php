<?php

declare(strict_types=1);

namespace App\Plugins\Context;

use App\Filament\Concerns\LimitsFormWidth;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Plugins\Api\ApiManifest;
use App\Plugins\Api\DashboardWidget;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\DataTable;
use App\Plugins\Api\Events;
use App\Plugins\Api\FieldRule;
use App\Plugins\Api\FormulaFunction;
use App\Plugins\Api\RecordListener;
use App\Plugins\Api\RecordRejected;
use App\Plugins\Api\Saft;
use App\Plugins\MenuSuggestion;
use App\Plugins\PluginServiceProvider;
use App\Plugins\SaftPlugin;
use App\Plugins\Testing\PluginTestCase;

/** api-reference.md des Entwickler-Kontexts: alle zugesicherten Klassen mit Signaturen und Doc-Kommentaren (Englisch). */
final class ApiReference
{
    /** Gruppen in der Reihenfolge der Referenz; Klassen, die hier fehlen, landen unter „Other“. */
    private const array GROUPS = [
        'Plugin class and registration' => [SaftPlugin::class, PluginServiceProvider::class, MenuSuggestion::class],
        'Data access' => [Saft::class, DataTable::class, RecordRejected::class],
        'Events (reacting to changes)' => [Events\RecordEvent::class, Events\RecordSaving::class, Events\RecordSaved::class, Events\RecordDeleting::class, Events\RecordDeleted::class, RecordListener::class],
        'Extensions' => [DataAction::class, DashboardWidget::class, FormulaFunction::class, FieldRule::class],
        'User interface' => [LimitsFormWidth::class],
        'Testing' => [PluginTestCase::class],
        'Types (only the class as a type with its Eloquent behaviour is guaranteed)' => [DynamicRecord::class, User::class],
    ];

    public function markdown(): string
    {
        $classes = ApiSurface::classes();
        $out = [
            '# SAFT plugin API reference',
            '',
            'Plugin API version **'.ApiManifest::current().'** (provided: '.implode(', ', ApiManifest::versions()).'), SAFT '.config('saft.version').'.',
            'Generated from the code. Only the classes and members listed here are guaranteed; every other SAFT class is',
            'internal and must not be used by plugins (`php artisan saft:plugins --check` reports violations).',
            'Doc comments are written in German; signatures are authoritative.',
            '',
        ];

        $listed = [];

        foreach ([...self::GROUPS, 'Other' => array_keys($classes)] as $group => $members) {
            $members = array_values(array_filter($members, static fn (string $c): bool => isset($classes[$c]) && ! isset($listed[$c])));

            if ($members === []) {
                continue;
            }

            $out[] = '## '.$group;
            $out[] = '';

            foreach ($members as $class) {
                $listed[$class] = true;
                $out = [...$out, ...$this->classBlock($class, $classes[$class])];
            }
        }

        return implode("\n", $out)."\n";
    }

    /**
     * @param  array{kind: string, parent: ?string, doc: string, members: list<array{signature: string, doc: string}>}  $class
     * @return list<string>
     */
    private function classBlock(string $name, array $class): array
    {
        $lines = ['### `'.$name.'`', ''];
        $lines[] = '*'.$class['kind'].($class['parent'] !== null ? ' extends `'.$class['parent'].'`' : '').'*';
        $lines[] = '';

        if ($class['doc'] !== '') {
            $lines[] = $class['doc'];
            $lines[] = '';
        }

        if ($class['members'] === []) {
            return $lines;
        }

        $lines[] = '```php';

        foreach ($class['members'] as $member) {
            if ($member['doc'] !== '') {
                foreach (explode("\n", $member['doc']) as $doc) {
                    $lines[] = '// '.$doc;
                }
            }

            $lines[] = $member['signature'].';';
        }

        $lines[] = '```';
        $lines[] = '';

        return $lines;
    }
}
