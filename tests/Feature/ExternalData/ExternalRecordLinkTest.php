<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\Ui\DynamicFormBuilder;
use App\DynamicData\Ui\KeylessRow;
use App\ExternalData\TableConnectorService;
use App\Filament\Support\ActivityChanges;
use App\Filament\Support\RecordLink;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Links aus dem Protokoll (z. B. Widget „Meine Änderungen“) und Schlüsseleingabe bei angebundenen Tabellen. */
class ExternalRecordLinkTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        ExternalResearchDatabase::on($this->externalDatabaseName())->reset();
        $this->connection = $this->researchConnection(['is_read_only' => false]);
        $this->actingAs($this->superAdmin());
    }

    private function connect(string $table): CustomTable
    {
        $service = app(TableConnectorService::class);

        return $service->update($service->connect($this->connection, ExternalResearchDatabase::SCHEMA, $table, ['name' => $table]), [
            'allow_create' => true, 'allow_update' => true, 'allow_delete' => true,
        ])->fresh('fields');
    }

    public function test_changes_to_connected_tables_link_to_the_table_connector(): void
    {
        $table = $this->connect('organisationen');
        $activity = Activity::query()->where('subject_type', $table->getMorphClass())->where('subject_id', $table->id)->latest('id')->firstOrFail();

        $url = RecordLink::forActivity($activity);
        $this->assertSame(route('filament.admin.resources.table-connector.edit', ['record' => $table]), $url);
        $this->get($url)->assertOk();

        // Felder verweisen auf ihre Tabelle.
        $field = $table->fields->firstWhere('name', 'name');
        app(TableConnectorService::class)->updateField($field, ['label' => ['de' => 'Bezeichnung']]);
        $fieldActivity = Activity::query()->where('subject_type', $field->getMorphClass())->where('subject_id', $field->id)->latest('id')->firstOrFail();
        $this->assertSame($url, RecordLink::forActivity($fieldActivity));
    }

    public function test_records_with_uuid_and_composite_keys_link_to_the_data_page(): void
    {
        $publications = $this->connect('publikationen');
        $authors = $this->connect('publikation_autoren');

        $publication = DynamicRecord::forTable($publications)->newQuery()->orderBy('titel')->firstOrFail();
        $publication->update(['jahr' => 2001]);
        $activity = Activity::query()->whereNull('subject_id')->where('properties->key', (string) $publication->getKey())->latest('id')->firstOrFail();

        $url = (string) RecordLink::forActivity($activity);
        $this->assertStringContainsString('/admin/data/publikationen', $url);
        $this->assertStringContainsString('sel=s.', urldecode($url));
        $this->get($url)->assertOk();

        // Zusammengesetzter Schlüssel: Liste ohne Auswahl.
        $assignment = DynamicRecord::forTable($authors)->newQuery()->orderBy('reihenfolge')->firstOrFail();
        $assignment->update(['reihenfolge' => 9]);
        $key = KeylessRow::encode(['publikation_id' => $assignment->publikation_id, 'person_id' => $assignment->person_id]);
        $activity = Activity::query()->whereNull('subject_id')->where('properties->key', $key)->latest('id')->firstOrFail();

        $url = (string) RecordLink::forActivity($activity);
        $this->assertStringContainsString('/admin/data/publikation_autoren', $url);
        $this->assertStringNotContainsString('sel=', $url);
        // Lesbare Kennung im Protokoll und in den Widgets.
        $this->assertSame('Daten: Publikation autoren #('.$assignment->publikation_id.', '.$assignment->person_id.')', ActivityChanges::subjectLabel($activity));
        $this->get($url)->assertOk();
    }

    public function test_key_columns_are_offered_on_create_even_if_read_only(): void
    {
        // Wie in der Demo-Konfiguration: publikation_id ist Verknüpfung zu Publikationen und steht auf „Nur lesen“.
        $this->connect('publikationen');
        $authors = $this->connect('publikation_autoren');
        $publicationId = $authors->fields->firstWhere('name', 'publikation_id');
        app(TableConnectorService::class)->updateField($publicationId, ['is_read_only' => true]);
        $authors = $authors->fresh('fields');
        DynamicRecord::forget('publikation_autoren');

        $names = array_map(fn ($c): string => $c->getName(), app(DynamicFormBuilder::class)->components($authors));
        $this->assertContains('publikation_id', $names);
        $this->assertContains('person_id', $names);

        $publication = (string) ExternalResearchDatabase::on($this->externalDatabaseName())->connection()->table('forschung.publikationen')->value('id');
        $person = (int) ExternalResearchDatabase::on($this->externalDatabaseName())->connection()->table('forschung.personen')->orderByDesc('id')->value('id');
        ExternalResearchDatabase::on($this->externalDatabaseName())->connection()->table('forschung.publikation_autoren')->where(['publikation_id' => $publication, 'person_id' => $person])->delete();

        DynamicRecord::forTable($authors)->create(['publikation_id' => $publication, 'person_id' => $person, 'reihenfolge' => 4]);
        $this->assertTrue(ExternalResearchDatabase::on($this->externalDatabaseName())->connection()->table('forschung.publikation_autoren')->where(['publikation_id' => $publication, 'person_id' => $person])->exists());
    }
}
