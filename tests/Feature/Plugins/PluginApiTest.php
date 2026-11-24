<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Pages\PluginsPage;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\WorkItem;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\DataTable;
use App\Plugins\Api\Events\RecordDeleting;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\RecordListener;
use App\Plugins\Api\RecordRejected;
use App\Plugins\Api\Saft;
use App\Plugins\PluginRegistry;
use App\Plugins\SaftPlugin;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Programmierschnittstelle für Plugins: Saft::table(), Ereignisse, Datenaktionen. */
class PluginApiTest extends DynamicDataTestCase
{
    private int $kunde;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'options' => ['length' => 40], 'is_required' => true],
        ]);
        $this->service()->createTable(['name' => 'auftraege', 'label' => 'Aufträge'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'options' => ['length' => 10], 'is_required' => true, 'is_unique' => true],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['options' => [['value' => 'offen'], ['value' => 'erledigt']]]],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer', 'validation_rules' => ['min' => 0, 'max' => 100]],
            ['name' => 'doppelt', 'label' => 'Doppelt', 'type' => 'computed', 'options' => ['result' => 'integer', 'formula' => 'menge * 2']],
            ['name' => 'start', 'label' => 'Start', 'type' => 'date'],
            ['name' => 'ende', 'label' => 'Ende', 'type' => 'date'],
        ]);
        $this->service()->updateTable(CustomTable::query()->with('fields')->where('name', 'auftraege')->sole(), ['validation_rules' => [
            ['left' => 'ende', 'operator' => '>=', 'right_kind' => 'field', 'right_field' => 'start', 'when_filled' => true],
        ]]);
        $this->kunde = (int) DynamicRecord::forTableName('kunden')->create(['firma' => 'ACME'])->getKey();
    }

    private function orders(): DataTable
    {
        return Saft::table('auftraege');
    }

    public function test_tables_are_found_by_name(): void
    {
        $this->actingAs($this->superAdmin());
        $this->assertTrue(Saft::hasTable('auftraege'));
        $this->assertFalse(Saft::hasTable('gibt_es_nicht'));
        $this->assertSame('Aufträge', $this->orders()->label());
        $this->assertSame(['nummer', 'kunde_id', 'status', 'menge', 'doppelt', 'start', 'ende'], $this->orders()->fieldNames());

        $this->expectException(InvalidArgumentException::class);
        Saft::table('gibt_es_nicht');
    }

    public function test_writes_validate_compute_and_log(): void
    {
        $this->actingAs($this->superAdmin());

        $order = $this->orders()->create(['nummer' => 'A-1', 'kunde_id' => $this->kunde, 'status' => 'offen', 'menge' => 4]);
        $this->assertSame(8, (int) $order->getAttribute('doppelt'), 'Berechnete Felder.');
        $this->assertTrue(Activity::query()->where('subject_id', $order->getKey())->where('event', 'created')->exists());

        $this->orders()->update($order->getKey(), ['menge' => 5, 'nummer' => 'A-1']);
        $this->assertSame(10, (int) $this->orders()->find($order->getKey())?->getAttribute('doppelt'), 'Eindeutig ohne sich selbst.');

        $this->orders()->delete($order);
        $this->assertNull($this->orders()->find($order->getKey()));
    }

    public function test_violations_are_reported_per_field(): void
    {
        $this->actingAs($this->superAdmin());
        $this->orders()->create(['nummer' => 'A-1']);

        $cases = [
            'nummer' => [['kunde_id' => $this->kunde], 'Nummer: Pflichtfeld ist leer.'],
            'kunde_id' => [['nummer' => 'A-2', 'kunde_id' => 999], 'Kunde: „999“ wurde nicht gefunden.'],
            'status' => [['nummer' => 'A-2', 'status' => 'fertig'], 'Status: „fertig“ ist kein Wert dieser Auswahlliste.'],
            'menge' => [['nummer' => 'A-2', 'menge' => 101], 'Menge: '],
            'ende' => [['nummer' => 'A-2', 'start' => '2026-05-02', 'ende' => '2026-05-01'], 'Ende'],
            'doppelt' => [['nummer' => 'A-2', 'doppelt' => 4], '„doppelt“ gibt es in Aufträge nicht'],
        ];

        foreach ($cases as $key => [$values, $message]) {
            try {
                $this->orders()->create($values);
                $this->fail("{$key} angenommen.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors(), $key);
                $this->assertStringContainsString($message, implode(' ', $e->errors()[$key]), $key);
            }
        }

        try {
            $this->orders()->create(['nummer' => 'A-1']);
            $this->fail('Doppelte Nummer angenommen.');
        } catch (ValidationException $e) {
            $this->assertSame(['Nummer: „A-1“ ist bereits vergeben.'], $e->errors()['nummer']);
        }

        $this->assertSame(1, DynamicRecord::forTableName('auftraege')->newQuery()->count());
    }

    public function test_data_permissions_apply_unless_switched_off(): void
    {
        $this->actingAs($this->userWithPermissions(['data.auftraege.view']));
        $this->assertTrue($this->orders()->can('view'));
        $this->assertFalse($this->orders()->can('create'));
        $this->assertSame(0, $this->orders()->query()->count());

        try {
            $this->orders()->create(['nummer' => 'A-1']);
            $this->fail('Ohne Recht angelegt.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Aufträge', $e->getMessage());
        }

        $this->orders()->withoutAuthorization()->create(['nummer' => 'A-1']);
        $this->assertSame(1, $this->orders()->query()->count());

        $this->actingAs($this->userWithPermissions([]));
        $this->expectException(AuthorizationException::class);
        $this->orders()->query();
    }

    public function test_connected_tables_are_read_only(): void
    {
        $this->actingAs($this->superAdmin());
        $external = new DataTable(new CustomTable(['name' => 'extern', 'label' => 'Extern', 'source' => CustomTable::SOURCE_EXTERNAL]));

        $this->expectException(BusinessRuleException::class);
        $external->withoutAuthorization()->create([]);
    }

    public function test_form_fields_come_from_the_table_designer(): void
    {
        $this->actingAs($this->superAdmin());
        $names = fn (array $fields): array => array_map(fn ($f): string => $f->getName(), $fields);

        $this->assertSame(['menge'], $names($this->orders()->formFields(only: ['menge'])));
        $this->assertNotContains('status', $names($this->orders()->formFields(except: ['status'])));
        $this->assertSame('Menge', $this->orders()->formFields(only: ['menge'])[0]->getLabel());
    }

    public function test_events_report_create_and_update_and_run_before_formulas(): void
    {
        $this->actingAs($this->superAdmin());
        $seen = [];
        Event::listen(RecordSaving::class, function (RecordSaving $e) use (&$seen): void {
            $seen[] = ['saving', $e->table, $e->creating()];

            // Vor den Formeln: der gesetzte Wert fliesst ins berechnete Feld.
            if ($e->table === 'auftraege' && $e->value('menge') !== null && (int) $e->value('menge') < 0) {
                $e->record->setAttribute('menge', abs((int) $e->value('menge')));
            }
        });
        Event::listen(RecordSaved::class, function (RecordSaved $e) use (&$seen): void {
            $seen[] = ['saved', $e->table, $e->creating(), $e->previous('menge'), $e->value('menge')];
        });

        $order = DynamicRecord::forTableName('auftraege')->create(['nummer' => 'A-1', 'menge' => -3]);
        $this->assertSame(6, (int) $order->getAttribute('doppelt'));
        $order->update(['menge' => 4]);

        $this->assertSame([
            ['saving', 'auftraege', true],
            ['saved', 'auftraege', true, null, 3],
            ['saving', 'auftraege', false],
            ['saved', 'auftraege', false, 3, 4],
        ], $seen, 'Ändern direkt nach dem Anlegen gilt als Ändern.');
    }

    public function test_rejections_stop_the_change_everywhere(): void
    {
        $this->actingAs($this->superAdmin());
        app()->setLocale('de');
        Event::listen(RecordSaving::class, function (RecordSaving $e): void {
            if ($e->table === 'auftraege' && $e->value('status') === 'erledigt') {
                throw new RecordRejected('Erledigte Aufträge nur über die Abrechnung.');
            }
        });
        Event::listen(RecordDeleting::class, function (RecordDeleting $e): void {
            if ($e->table === 'auftraege') {
                throw new RecordRejected('Aufträge werden nicht gelöscht.');
            }
        });

        // Schnittstelle
        try {
            $this->orders()->create(['nummer' => 'A-1', 'status' => 'erledigt']);
            $this->fail('Abgelehnte Änderung gespeichert.');
        } catch (RecordRejected $e) {
            $this->assertSame('Erledigte Aufträge nur über die Abrechnung.', $e->getMessage());
        }

        // Datenansicht: Meldung, nichts gespeichert
        Livewire::test(DynamicDataPage::class, ['table' => 'auftraege'])
            ->callAction(TestAction::make(CreateAction::class)->table(), ['nummer' => 'A-2', 'status' => 'erledigt']);
        $notification = $this->lastNotification();
        $this->assertSame(__('errors.titles.business_rule'), $notification->getTitle());
        $this->assertStringContainsString('Erledigte Aufträge nur über die Abrechnung.', (string) $notification->getBody());
        $this->assertSame(0, DynamicRecord::forTableName('auftraege')->newQuery()->count());

        // Import: Fehler der Zeile
        $this->service()->updateTable(CustomTable::query()->with('fields')->where('name', 'auftraege')->sole(), ['import_enabled' => true]);
        $path = (string) tempnam(sys_get_temp_dir(), 'saft-test-');
        file_put_contents($path, "Nummer;Status\nA-3;offen\nA-4;erledigt\n");

        Storage::fake('local');
        $item = $this->importRecords(CustomTable::query()->with('fields')->where('name', 'auftraege')->sole(), $path, 'auftraege.csv');
        unlink($path);
        $this->assertSame(WorkItem::FAILED, $item->status, 'Import trotz Ablehnung.');
        $this->assertStringContainsString('Zeile 3', (string) $item->message);
        $this->assertStringContainsString('Erledigte Aufträge nur über die Abrechnung.', (string) $item->message);

        // Zurückgenommen, obwohl das Plugin auch das Löschen ablehnt: Aufräumen fragt Plugins nicht.
        $this->assertSame(0, DynamicRecord::forTableName('auftraege')->newQuery()->withTrashed()->count(), 'Alles oder nichts.');

        $order = $this->orders()->create(['nummer' => 'A-5']);
        $this->expectException(RecordRejected::class);
        $this->orders()->delete($order);
    }

    private function lastNotification(): Notification
    {
        app(NotificationMailbox::class)->flush();
        $component = new Notifications;
        $component->mount();
        $component->pullNotifications();

        return $component->notifications->last();
    }

    public function test_plugin_events_can_be_muted(): void
    {
        $this->actingAs($this->superAdmin());
        $count = 0;
        Event::listen(RecordSaved::class, function () use (&$count): void {
            $count++;
        });

        DynamicRecord::withoutPluginEvents(fn () => DynamicRecord::forTableName('auftraege')->create(['nummer' => 'A-1']));
        $this->assertSame(0, $count);
        DynamicRecord::forTableName('auftraege')->create(['nummer' => 'A-2']);
        $this->assertSame(1, $count);
    }

    public function test_plugin_listeners_and_data_actions_are_wired_in(): void
    {
        $this->actingAs($this->superAdmin());
        $plugin = new class extends SaftPlugin
        {
            public static int $calls = 0;

            public function id(): string
            {
                return 'test-erweiterung';
            }

            public function label(): string
            {
                return 'Test';
            }

            public function recordListeners(): array
            {
                return [new RecordListener('auftraege', RecordSaved::class, static function (): void {
                    self::$calls++;
                })];
            }

            public function dataActions(): array
            {
                return [
                    new DataAction('auftraege', DataAction::TABLE, static fn (): Action => Action::make('testKopf')->label('Kopfaktion')),
                    new DataAction('auftraege', DataAction::RECORD, static fn (): Action => Action::make('testZeile')->label('Zeilenaktion')),
                    new DataAction('kunden', DataAction::RECORD, static fn (): Action => Action::make('testKunde')),
                ];
            }
        };
        app(PluginRegistry::class)->add($plugin);
        app(PluginRegistry::class)->listen($plugin);

        DynamicRecord::forTableName('kunden')->create(['firma' => 'Beta']);
        $order = DynamicRecord::forTableName('auftraege')->create(['nummer' => 'A-1']);
        $this->assertSame(1, $plugin::$calls, 'Nur für die eigene Tabelle.');

        Livewire::test(DynamicDataPage::class, ['table' => 'auftraege'])
            ->assertActionExists(TestAction::make('testKopf')->table())
            ->assertActionExists(TestAction::make('testZeile')->table($order))
            ->assertActionDoesNotExist(TestAction::make('testKunde')->table($order));

        Livewire::test(PluginsPage::class)->assertSee('Prüft Änderungen: Aufträge')->assertSee('Aktionen in: Aufträge, Kunden');
    }

    public function test_notifications_reach_the_bell_without_a_queue_worker(): void
    {
        $user = $this->superAdmin();
        Saft::notify($user, Notification::make()->title('Hallo'));

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }
}
