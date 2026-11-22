<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Documents\Assistant\TemplateAssistant;
use App\Filament\Resources\DocumentTemplates\Pages\CreateDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\Role;
use App\Reports\Assistant\AssistantClient;
use App\Settings\AppSettings;
use Database\Seeders\GitarrenshopDemoSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Fakes\FakeAssistantClient;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** KI-Assistent für Dokumentvorlagen (B-124, D2a) – mit Modell-Attrappe, ohne Netz. */
class TemplateAssistantTest extends DynamicDataTestCase
{
    private FakeAssistantClient $fake;

    private CustomTable $receipts;

    private DocumentTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        Storage::fake('local');
        $this->seed(GitarrenshopDemoSeeder::class);
        $this->receipts = CustomTable::query()->where('name', 'gitarren_belege')->sole();
        $this->template = DocumentTemplate::query()->where('uuid', GitarrenshopDemoSeeder::INVOICE_TEMPLATE)->sole();

        config(['ai.provider' => 'anthropic', 'ai.daily_limit' => 50]);
        $settings = app(AppSettings::class);
        $settings->ai_assistant_enabled = true;
        $settings->ai_send_choices = false;
        $settings->save();

        $this->fake = new FakeAssistantClient;
        $this->app->instance(AssistantClient::class, $this->fake);
    }

    /** @return array<string, string> */
    private function proposal(string $body, array $overrides = []): array
    {
        return [...[
            'kind' => 'template', 'question' => '', 'name' => 'Mahnung', 'recipient' => "{{kunde_id}}\n{{kunde_id.ort}}",
            'body' => $body, 'file_name' => 'Mahnung-{{belegnummer}}', 'note' => 'Mahnung mit offenen Positionen.',
        ], ...$overrides];
    }

    public function test_proposal_replaces_the_template_in_the_form_and_can_be_undone(): void
    {
        $this->actingAs($this->superAdmin());
        $this->fake->push($this->proposal("<h1>Mahnung {{belegnummer}}</h1>\n{{#gitarren_buchungen}}<p>{{artikel_id}}</p>{{/gitarren_buchungen}}"));
        $original = $this->template->body;

        $page = Livewire::test(EditDocumentTemplate::class, ['record' => $this->template->getKey()])
            ->assertSee(__('documents.assistant.title'))
            ->set('assistantPrompt', 'Mahnung statt Rechnung')
            ->call('askAssistant')
            ->assertSet('data.body', "<h1>Mahnung {{belegnummer}}</h1>\n{{#gitarren_buchungen}}<p>{{artikel_id}}</p>{{/gitarren_buchungen}}\n")
            ->assertSet('data.recipient', "{{kunde_id}}\n{{kunde_id.ort}}")
            ->assertSet('data.file_name', 'Mahnung-{{belegnummer}}')
            ->assertSet('data.name', 'Rechnung')
            ->assertSee('Mahnung mit offenen Positionen.');

        $this->assertSame($original, $this->template->fresh()->body, 'Gespeichert wird erst mit Speichern.');

        // Nur Struktur und Vorlage gehen an die KI, nie Datensätze; Auswahlwerte nur mit der Einstellung.
        $sent = $this->fake->sentText();
        $this->assertStringContainsString('gitarren_buchungen', $sent);
        $this->assertStringContainsString('Mahnung statt Rechnung', $sent);
        $this->assertStringNotContainsString('V-2025-001', $sent);
        $this->assertStringNotContainsString('"choices"', $sent);

        $page->call('undoAssistant')->assertSet('data.body', $original)->assertSet('data.file_name', $this->template->file_name);

        $log = Activity::query()->where('event', 'assistant')->latest('id')->firstOrFail();
        $this->assertSame(['documents', 'Mahnung statt Rechnung', 'template'], [$log->log_name, $log->attribute_changes['attributes']['anfrage'], $log->attribute_changes['attributes']['ergebnis']]);
    }

    public function test_errors_and_pdf_warnings_go_back_once_for_correction(): void
    {
        $user = $this->superAdmin();
        $this->fake
            ->push($this->proposal('<div style="display: flex">{{gibtsnicht}}</div>'))
            ->push($this->proposal('<table><tr><td>{{belegnummer}}</td></tr></table>'));

        $result = app(TemplateAssistant::class)->propose($user, $this->receipts, 'Mahnung', []);

        $this->assertSame('template', $result->status);
        $correction = (string) end($this->fake->requests[1]['messages'])['content'];
        $this->assertStringContainsString('gibtsnicht', $correction);
        $this->assertStringContainsString('Flexbox', $correction);
    }

    public function test_question_and_new_template_with_name(): void
    {
        $this->actingAs($this->superAdmin());
        $this->fake
            ->push(['kind' => 'question', 'question' => 'Mit oder ohne Positionen?', 'name' => '', 'recipient' => '', 'body' => '', 'file_name' => '', 'note' => ''])
            ->push($this->proposal('<p>{{belegnummer}}</p>'));

        Livewire::test(CreateDocumentTemplate::class)
            ->fillForm(['app_id' => $this->receipts->app_id, 'custom_table_id' => $this->receipts->id])
            ->set('assistantPrompt', 'Mahnung')
            ->call('askAssistant')
            ->assertSet('assistantReplyKind', 'question')
            ->assertSee('Mit oder ohne Positionen?')
            ->set('assistantPrompt', 'ohne')
            ->call('askAssistant')
            ->assertSet('data.name', 'Mahnung')
            ->assertSet('data.body', "<p>{{belegnummer}}</p>\n");

        $this->assertCount(3, $this->fake->requests[1]['messages'], 'Anfrage, Rückfrage und Antwort im Verlauf.');
    }

    public function test_needs_its_own_app_permission_and_the_switched_on_assistant(): void
    {
        $role = Role::query()->create(['name' => 'vorlagen', 'guard_name' => 'web', 'app_id' => $this->receipts->app_id]);
        $role->givePermissionTo('documents.manage');
        $user = $this->userWithPermissions();
        $user->assignRole($role);

        $this->assertFalse(TemplateAssistant::availableFor($user->fresh(), $this->receipts->app_id), 'Verwalten allein genügt nicht.');

        $role->givePermissionTo(TemplateAssistant::PERMISSION);
        $this->assertTrue(TemplateAssistant::availableFor($user->fresh(), $this->receipts->app_id));
        $this->assertFalse(TemplateAssistant::availableFor($user->fresh(), $this->receipts->app_id + 999), 'Nur in der App der Rolle.');

        $this->actingAs($user->fresh());
        Livewire::test(EditDocumentTemplate::class, ['record' => $this->template->getKey()])->assertSee(__('documents.assistant.title'));

        $settings = app(AppSettings::class);
        $settings->ai_assistant_enabled = false;
        $settings->save();
        $this->assertFalse(TemplateAssistant::availableFor($user->fresh(), $this->receipts->app_id));
    }
}
