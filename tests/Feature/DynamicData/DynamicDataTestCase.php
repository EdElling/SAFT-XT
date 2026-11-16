<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\Models\CustomField;
use App\Models\CustomTable;
use App\Services\DynamicSchemaService;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

abstract class DynamicDataTestCase extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    /** Auch app_data in der Test-Transaktion (PostgreSQL: DDL wird mit zurückgerollt). */
    protected array $connectionsToTransact = [null, 'app_data'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
    }

    protected function service(): DynamicSchemaService
    {
        return app(DynamicSchemaService::class);
    }

    protected function appSchema(): Builder
    {
        return DB::connection('app_data')->getSchemaBuilder();
    }

    protected function fieldId(string $table, string $field): int
    {
        return (int) CustomField::query()
            ->whereHas('custom_table', fn ($q) => $q->where('name', $table))
            ->where('name', $field)
            ->value('id');
    }

    protected function projects(): CustomTable
    {
        return $this->service()->createTable(
            ['name' => 'projekte', 'label' => 'Projekte'],
            [
                ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'options' => ['length' => 100], 'is_required' => true],
                ['name' => 'budget', 'label' => 'Budget', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
                ['name' => 'anzahl', 'label' => 'Anzahl', 'type' => 'integer'],
                ['name' => 'aktiv', 'label' => 'Aktiv', 'type' => 'boolean'],
                ['name' => 'start', 'label' => 'Start', 'type' => 'date'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['options' => [['value' => 'offen'], ['value' => 'erledigt']]]],
            ],
        );
    }
}
