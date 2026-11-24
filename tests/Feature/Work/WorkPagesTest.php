<?php

declare(strict_types=1);

namespace Tests\Feature\Work;

use App\Filament\Resources\WorkItems\Pages\ListWorkItems;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Filament\Widgets\TasksStatusWidget;
use App\Livewire\WorkProgress;
use App\Models\User;
use App\Models\WorkItem;
use App\Work\WorkRunner;
use App\Work\WorkService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Fixtures\Work\CountingWork;
use Tests\TestCase;

/** Seite „Arbeiten“, Fortschritt im Browser, Download, Benachrichtigung und Dashboard (Fundament 2, F3a). */
class WorkPagesTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        config(['work.types' => [...(array) config('work.types'), CountingWork::class]]);
        Storage::fake('local');
    }

    /** @param  array<string, mixed>  $parameters */
    private function workOf(?User $user, array $parameters = ['total' => 9, 'per_step' => 3]): WorkItem
    {
        return app(WorkService::class)->start(CountingWork::KEY, $parameters, $user);
    }

    public function test_people_see_their_own_work_and_admins_all(): void
    {
        $anna = $this->userWithPermissions();
        $admin = $this->userWithPermissions(['work.view_any']);
        $own = $this->workOf($anna);
        $foreign = $this->workOf($admin);
        $system = $this->workOf(null);

        $this->actingAs($anna);
        Livewire::test(ListWorkItems::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign, $system]);
        $this->get(WorkItemResource::getUrl('view', ['record' => $own]))->assertOk()->assertSee('Zählen');
        $this->get(WorkItemResource::getUrl('view', ['record' => $foreign]))->assertNotFound();

        $this->actingAs($admin);
        Livewire::test(ListWorkItems::class)->assertCanSeeTableRecords([$own, $foreign, $system]);
        Livewire::withQueryParams(['tab' => ListWorkItems::MINE])->test(ListWorkItems::class)
            ->assertCanSeeTableRecords([$foreign])->assertCanNotSeeTableRecords([$own, $system]);
    }

    public function test_the_browser_of_the_starting_person_drives_the_work(): void
    {
        $anna = $this->userWithPermissions();
        $admin = $this->userWithPermissions(['work.view_any']);
        $item = $this->workOf($anna);

        // Andere sehen nur zu.
        $this->actingAs($admin);
        Livewire::test(WorkProgress::class, ['workId' => $item->id])->call('advance')->assertSee('Läuft im Hintergrund');
        $this->assertSame(WorkItem::QUEUED, $item->fresh()->status);

        $this->actingAs($anna);
        Livewire::test(WorkProgress::class, ['workId' => $item->id])
            ->assertSee('solange diese Seite offen ist')
            ->call('advance')
            ->assertSee('Gezählt bis 9')
            ->assertDontSee('solange diese Seite offen ist');
        $this->assertSame(WorkItem::DONE, $item->fresh()->status);
        // Im Browser zu Ende gegangen: keine Benachrichtigung.
        $this->assertSame(0, $anna->notifications()->count());
    }

    public function test_cancelling_needs_the_right(): void
    {
        $anna = $this->userWithPermissions();
        $bruno = $this->userWithPermissions(['work.view_any']);
        $item = $this->workOf($anna);

        $this->actingAs($bruno);
        Livewire::test(ListWorkItems::class)->assertActionHidden(TestAction::make('cancel')->table($item));
        // Verweigert (Filament zeigt die 403 als Meldung): die Arbeit bleibt offen.
        Livewire::test(WorkProgress::class, ['workId' => $item->id])->call('cancel');
        $this->assertSame(WorkItem::QUEUED, $item->fresh()->status);

        $bruno->givePermissionTo('work.cancel_any');
        Livewire::test(ListWorkItems::class)->callAction(TestAction::make('cancel')->table($item));
        $this->assertSame(WorkItem::CANCELLED, $item->fresh()->status);

        $this->actingAs($anna);
        $own = $this->workOf($anna);
        Livewire::test(WorkProgress::class, ['workId' => $own->id])->call('cancel');
        $this->assertSame(WorkItem::CANCELLED, $own->fresh()->status);
    }

    public function test_result_files_are_downloadable_until_they_expire(): void
    {
        $anna = $this->userWithPermissions();
        $item = app(WorkRunner::class)->advance($this->workOf($anna, ['total' => 1, 'file' => true]), 30, interactive: true);
        $this->assertSame('ergebnis.txt', $item->resultFile());

        $this->actingAs($anna)->get(route('work.download', $item->uuid))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('ergebnis.txt');
        $this->actingAs($this->userWithPermissions())->get(route('work.download', $item->uuid))->assertForbidden();
        $this->actingAs($this->userWithPermissions(['work.view_any']))->get(route('work.download', $item->uuid))->assertOk();

        $this->travel(25)->hours();
        $this->actingAs($anna)->get(route('work.download', $item->uuid))->assertStatus(410);
    }

    public function test_work_finished_in_the_background_notifies_the_person(): void
    {
        $anna = $this->userWithPermissions();
        app(WorkRunner::class)->advance($this->workOf($anna), 30);
        app(WorkRunner::class)->advance($this->workOf($anna, ['total' => 9, 'fail_at' => 3]), 30);

        $this->assertSame(
            ['„Zählen“ ist fehlgeschlagen', '„Zählen“ ist fertig'],
            $anna->notifications()->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn ($n): string => $n->data['title'])->sort()->values()->all(),
        );
    }

    public function test_error_details_need_the_right(): void
    {
        $item = app(WorkRunner::class)->advance($this->workOf(null, ['total' => 9, 'fail_at' => 3]), 30);

        $this->actingAs($this->userWithPermissions(['work.view_any']));
        Livewire::test(ListWorkItems::class)->assertActionHidden(TestAction::make('details')->table($item));

        $this->actingAs($this->userWithPermissions(['work.view_any', 'work.details']));
        Livewire::test(ListWorkItems::class)->assertActionVisible(TestAction::make('details')->table($item));
    }

    public function test_dashboard_reports_stuck_and_failed_work(): void
    {
        $this->actingAs($this->superAdmin());
        $stuck = $this->workOf(null);
        $stuck->forceFill(['status' => WorkItem::RUNNING, 'heartbeat_at' => now()->subMinutes(30)])->save();
        app(WorkRunner::class)->advance($this->workOf(null, ['total' => 9, 'fail_at' => 3]), 30);

        Livewire::test(TasksStatusWidget::class)
            ->assertSee('1 Arbeit ohne Fortschritt seit über 10 Minuten')
            ->assertSee('1 Arbeit in den letzten 24 Stunden fehlgeschlagen');
    }

    public function test_user_menu_links_to_my_work(): void
    {
        $this->actingAs($this->userWithPermissions())->get(WorkItemResource::getUrl('index', ['tab' => ListWorkItems::MINE]))->assertOk();
        $this->get('/admin')->assertSee('Meine Arbeiten');
    }
}
