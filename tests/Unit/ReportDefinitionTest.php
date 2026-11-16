<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Reports\ReportDefinition;
use PHPUnit\Framework\TestCase;

/** Speicherformat der Auswertungen (Version 1): normalisieren und unverändert zurückschreiben. */
class ReportDefinitionTest extends TestCase
{
    public function test_round_trip_and_normalisation(): void
    {
        $definition = ReportDefinition::fromArray([
            'base' => '12',
            'fields' => [['key' => 'f1', 'path' => ['57'], 'field' => '31', 'bucket' => ' ', 'label' => ' Ort '], 'kaputt'],
            'groups' => ['f1', ['x']],
            'measures' => [['key' => 'm1', 'agg' => 'count']],
            'filters' => [['field' => 'f1', 'op' => 'in', 'value' => ['ber', 'ham']]],
            'sort' => [['key' => 'm1', 'dir' => 'DESC'], ['key' => 'f1', 'dir' => 'desc']],
            'limit' => '100',
            'visual' => 'bar',
        ]);

        $this->assertSame([
            'version' => 1,
            'base' => 12,
            'fields' => [['key' => 'f1', 'path' => [57], 'field' => 31, 'bucket' => null, 'label' => 'Ort']],
            'groups' => ['f1'],
            'columns' => [],
            'measures' => [['key' => 'm1', 'field' => null, 'agg' => 'count', 'label' => null]],
            'filters' => [['field' => 'f1', 'op' => 'in', 'value' => ['ber', 'ham']]],
            'sort' => [['key' => 'm1', 'dir' => 'asc'], ['key' => 'f1', 'dir' => 'desc']],
            'limit' => 100,
            'visual' => ['type' => 'table'],
            'show' => null,
        ], $definition->toArray());
        $this->assertTrue($definition->isGrouped());
        $this->assertEquals($definition, ReportDefinition::fromArray($definition->toArray()));
        $this->assertFalse(ReportDefinition::fromArray(['base' => 1, 'fields' => [['key' => 'a', 'field' => 2]]])->isGrouped());
    }
}
