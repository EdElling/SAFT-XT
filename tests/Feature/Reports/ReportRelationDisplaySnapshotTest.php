<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\ExternalData\Snapshots\SnapshotService;
use App\Models\CustomTable;
use App\Reports\ReportDefinition;
use App\Reports\ReportRunner;
use App\Reports\ValueFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** „Welche Personen haben die meisten Publikationen?“ über Datenstände: Namen statt Schlüssel. */
class ReportRelationDisplaySnapshotTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    protected array $connectionsToTransact = [null, 'app_data'];

    public function test_authors_per_person_show_names_from_the_snapshot(): void
    {
        app()->setLocale('de');
        $this->seedAdminStructure();
        $this->prepareExternalDatabase();
        $this->connectResearchDemoTables($this->researchConnection());
        $admin = $this->superAdmin();

        $authors = CustomTable::query()->where('name', 'fdb_publikation_autoren')->sole();
        $person = $authors->fields()->where('name', 'person_id')->sole();
        $definition = ReportDefinition::fromArray([
            'base' => $authors->id,
            'fields' => [['key' => 'f1', 'path' => [], 'field' => $person->id]],
            'groups' => ['f1'],
            'measures' => [['key' => 'm1', 'field' => null, 'agg' => 'count']],
            'sort' => [['key' => 'm1', 'dir' => 'desc']],
            'limit' => 5,
            'visual' => ['type' => 'bar'],
        ]);

        // Der Editor erzeugt die Datenstände beim Wählen der Tabelle; hier von Hand.
        $runner = app(ReportRunner::class);
        foreach (['fdb_publikation_autoren', 'fdb_personen'] as $name) {
            app(SnapshotService::class)->refresh(CustomTable::query()->where('name', $name)->sole(), $admin);
        }

        $result = $runner->run($definition, $admin);
        $column = $result->column('f1');
        $labels = array_map(fn (array $row): string => app(ValueFormatter::class)->format($row['f1'], $column), $result->rows);

        $this->assertNotEmpty($labels);
        foreach ($labels as $label) {
            $this->assertMatchesRegularExpression('/^\p{L}[\p{L}\-\']* \p{L}/u', $label, 'Vor- und Nachname statt Nummer.');
        }
        $this->assertNotSame((string) $result->rows[0]['f1'], $labels[0]);
    }
}
