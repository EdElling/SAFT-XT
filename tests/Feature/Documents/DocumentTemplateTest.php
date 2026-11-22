<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Documents\Templates\TemplateChecker;
use App\Documents\Templates\TemplateRenderer;
use App\Documents\Templates\TemplateSkeletons;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\DocumentTemplates\Pages\CreateDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Schemas\DocumentTemplateForm;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\Role;
use Database\Seeders\GitarrenshopDemoSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Inhaltsvorlagen (B-124, D1b): Platzhalter, Formatierungen, Kind-Tabellen, Prüfung, Grundgerüste, Seiten. */
class DocumentTemplateTest extends DynamicDataTestCase
{
    private CustomTable $receipts;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        Storage::fake('local');
        $this->seed(GitarrenshopDemoSeeder::class);
        $this->receipts = CustomTable::query()->where('name', 'gitarren_belege')->sole();
    }

    private function template(array $attributes = []): DocumentTemplate
    {
        return DocumentTemplate::query()->create([
            'app_id' => $this->receipts->app_id,
            'custom_table_id' => $this->receipts->id,
            'name' => 'Testrechnung',
            'recipient' => "{{kunde_id.vorname}} {{kunde_id.name}}\n{{kunde_id.strasse}}\n\n{{kunde_id.plz}} {{kunde_id.ort}}",
            'body' => '<h1>Rechnung {{belegnummer}}</h1>{{#gitarren_buchungen}}<p>{{text}}: {{netto | CHF}} / {{art}}</p>{{/gitarren_buchungen}}<p>{{summe | CHF}} {{datum | DatumLang}}</p>',
            'file_name' => 'Rechnung-{{belegnummer}}',
            ...$attributes,
        ]);
    }

    public function test_renders_fields_relations_children_and_formats(): void
    {
        $record = DynamicRecord::forTableName('gitarren_belege')->newQuery()->where('belegnummer', 'V-2025-001')->sole();

        $document = app(TemplateRenderer::class)->render($this->template(), $record, $this->superAdmin());

        $this->assertSame('Rechnung-V-2025-001.pdf', $document->fileName);
        $this->assertSame('Lena Meier<br>Bahnhofstrasse 12<br>8001 Zürich', $document->recipient);
        $this->assertStringContainsString('<h1>Rechnung V-2025-001</h1>', $document->html);
        $this->assertStringContainsString('450.00 / Verkauf', $document->html);
        $this->assertStringContainsString('486.45 1. Juni 2025', $document->html);
        $this->assertStringStartsWith('%PDF-', $document->pdf);
    }

    public function test_create_and_edit_pages_with_skeleton_preview_and_check(): void
    {
        $this->actingAs($this->superAdmin());
        $this->get(DocumentTemplateResource::getUrl('index'))->assertOk();

        Livewire::test(CreateDocumentTemplate::class)
            ->fillForm(['name' => 'Lieferschein', 'app_id' => $this->receipts->app_id])
            ->set('data.custom_table_id', (string) $this->receipts->id)
            ->assertSet('data.file_name', 'Rechnung-{{belegnummer}}')
            ->call('refreshPreview')
            ->assertDispatched('document-template-preview', fn (string $name, array $params): bool => is_string($params['pdf'] ?? null))
            ->call('create')
            ->assertHasNoFormErrors();

        $template = DocumentTemplate::query()->where('name', 'Lieferschein')->sole();
        $this->assertStringContainsString('{{#gitarren_buchungen}}', $template->body);
        $this->assertSame([], app(TemplateChecker::class)->check($this->receipts, $template->body, $template->recipient, $template->file_name)['errors']);

        Livewire::test(EditDocumentTemplate::class, ['record' => $template->getKey()])
            ->fillForm(['body' => '<p>{{gibtsnicht}}</p>'])
            ->call('save')
            ->assertHasFormErrors(['body']);
    }

    public function test_skeletons_pass_the_check(): void
    {
        foreach (TemplateSkeletons::KINDS as $kind) {
            $skeleton = TemplateSkeletons::make($kind, $this->receipts);
            $this->assertSame([], app(TemplateChecker::class)->check($this->receipts, $skeleton['body'], $skeleton['recipient'], $skeleton['file_name'])['errors'], $kind);
        }
    }

    public function test_demo_invoice_template_passes_the_check_and_renders(): void
    {
        $template = DocumentTemplate::query()->where('uuid', GitarrenshopDemoSeeder::INVOICE_TEMPLATE)->sole();
        $this->assertSame([], app(TemplateChecker::class)->check($this->receipts, $template->body, $template->recipient, $template->file_name)['errors']);

        $record = DynamicRecord::forTableName('gitarren_belege')->newQuery()->where('belegnummer', 'V-2025-001')->sole();
        $document = app(TemplateRenderer::class)->render($template, $record, $this->superAdmin());

        $this->assertStringContainsString('Total CHF', $document->html);
        $this->assertSame('Rechnung-V-2025-001.pdf', $document->fileName);
        $this->seed(GitarrenshopDemoSeeder::class);
        $this->assertSame(1, DocumentTemplate::query()->where('uuid', GitarrenshopDemoSeeder::INVOICE_TEMPLATE)->count(), 'Nur einmal angelegt.');
    }

    public function test_checker_reports_errors_and_warnings(): void
    {
        $check = fn (string $body, string $recipient = '', string $fileName = ''): array => app(TemplateChecker::class)->check($this->receipts, $body, $recipient, $fileName);

        $this->assertSame([], $check('{{belegnummer}} {{kunde_id.ort}} {{#gitarren_buchungen}}{{netto | Zahl:2}}{{#artikel_id}}{{bezeichnung}}{{/artikel_id}}{{/gitarren_buchungen}} {{absender.iban}} {{heute | DatumLang}} {{.}}')['errors']);
        $this->assertStringContainsString('{{gibtsnicht}} in Zeile 2', $check("ok\n{{gibtsnicht}}")['errors'][0]);
        $this->assertStringContainsString('„chf“', $check('{{summe | chf}}')['errors'][0], 'Formatierungen gross geschrieben.');
        $this->assertStringContainsString('nicht geschlossen', $check('{{#gitarren_buchungen}}{{text}}')['errors'][0]);
        $this->assertStringContainsString('schliesst keinen', implode(' ', $check('{{belegnummer}}{{/gitarren_buchungen}}')['errors']));
        $this->assertStringContainsString('{{text}}', $check('{{text}}')['errors'][0], 'Feld der Kind-Tabelle nur im Abschnitt.');
        $this->assertNotSame([], $check('{{> teil}}')['errors']);
        $this->assertNotSame([], $check('{{absender.unbekannt}}')['errors']);
        $this->assertStringStartsWith('Empfänger:', $check('', '{{kunde_id.gibtsnicht}}')['errors'][0]);
        $this->assertStringStartsWith('Dateiname:', $check('', '', 'R-{{#offen}}')['errors'][0]);
        $this->assertCount(3, $check('<div style="display: flex; float: left"><img src="https://example.org/a.png"></div>')['warnings']);
    }

    public function test_language_field_lines_raw_and_file_name(): void
    {
        $customers = CustomTable::query()->where('name', 'gitarren_kunden')->sole();
        $template = new DocumentTemplate([
            'app_id' => $customers->app_id, 'custom_table_id' => $customers->id, 'name' => 'Datenblatt',
            'body' => '<p>{{land}} / {{land | Roh}} / {{newsletter}}{{^newsletter}}nein{{/newsletter}}</p><p>{{bemerkung | Zeilen}}</p>{{#gitarren_belege}}<p>{{datum}} {{summe}}</p>{{/gitarren_belege}}',
            'file_name' => 'Kunde: {{name}} / {{kundennummer}}?',
            'language_field' => 'sprache',
        ]);
        $record = DynamicRecord::forTableName('gitarren_kunden')->newQuery()->where('name', 'Meier')->firstOrFail();
        $record->setAttribute('bemerkung', "Zeile 1\nZeile 2 <b>");
        $record->setAttribute('newsletter', false);

        $document = app(TemplateRenderer::class)->render($template, $record, $this->superAdmin());

        $this->assertSame('de', $document->locale, 'Ohne gültiges Sprachfeld die Sprache der Person.');
        $this->assertStringContainsString('Zeile 1<br>Zeile 2 &lt;b&gt;', $document->html);
        $this->assertStringContainsString('nein', $document->html);
        $this->assertMatchesRegularExpression('/^Kunde- '.preg_quote((string) $record->name, '/').' - [^?]+\.pdf$/u', $document->fileName);
        $this->assertSame('Dokument.pdf', TemplateRenderer::fileName(' / ', ''));

        $record->setAttribute('sprache', 'fr');
        $this->assertSame('fr', app(TemplateRenderer::class)->locale($template, $record));
    }

    public function test_child_tables_and_relations_need_the_view_permission(): void
    {
        $record = DynamicRecord::forTableName('gitarren_belege')->newQuery()->where('belegnummer', 'V-2025-001')->sole();
        $template = $this->template(['body' => '<p>[{{kunde_id}}|{{kunde_id.ort}}]</p>{{#gitarren_buchungen}}<p>{{text}}</p>{{/gitarren_buchungen}}']);
        $user = $this->userWithPermissions(['data.gitarren_belege.view']);

        $html = app(TemplateRenderer::class)->render($template, $record, $user)->html;

        $this->assertStringContainsString('[Lena Meier|]', $html, 'Anzeige der Verknüpfung ja, Felder des Kunden nicht.');
        $this->assertStringNotContainsString('Stratocaster', $html);

        $user->givePermissionTo(['data.gitarren_kunden.view', 'data.gitarren_buchungen.view']);
        $html = app(TemplateRenderer::class)->render($template, $record, $user->fresh())->html;
        $this->assertStringContainsString('[Lena Meier|Zürich]', $html);
        $this->assertStringContainsString('Stratocaster', $html);
    }

    public function test_access_only_to_templates_of_apps_with_the_permission(): void
    {
        $own = $this->template();
        $other = DocumentTemplate::query()->create(['app_id' => self::ensureTestApp()->id, 'custom_table_id' => $this->receipts->id, 'name' => 'Fremd', 'body' => '<p>x</p>']);

        $this->actingAs($this->userWithPermissions(['users.view']));
        $this->get(DocumentTemplateResource::getUrl('index'))->assertForbidden();

        $role = Role::query()->create(['name' => 'shop-admin', 'guard_name' => 'web', 'app_id' => $this->receipts->app_id]);
        $role->givePermissionTo('documents.manage');
        $user = $this->userWithPermissions();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->get(DocumentTemplateResource::getUrl('index'))->assertOk();
        $this->assertEqualsCanonicalizing([$own->id, DocumentTemplate::query()->where('uuid', GitarrenshopDemoSeeder::INVOICE_TEMPLATE)->value('id')], DocumentTemplateResource::getEloquentQuery()->pluck('id')->all());
        $this->get(DocumentTemplateResource::getUrl('edit', ['record' => $own]))->assertOk();
        $this->get(DocumentTemplateResource::getUrl('edit', ['record' => $other]))->assertNotFound();
    }

    public function test_picker_offers_fields_relations_children_and_formats(): void
    {
        $groups = collect(DocumentTemplateForm::pickerGroups($this->receipts));
        $inserts = $groups->flatMap(static fn (array $g): array => array_column($g['items'], 'insert'));

        $this->assertContains('{{belegnummer}}', $inserts);
        $this->assertContains('{{kunde_id.ort}}', $inserts);
        $this->assertContains("{{#gitarren_buchungen}}\n\n{{/gitarren_buchungen}}", $inserts);
        $this->assertContains('{{absender.adresse | Zeilen}}', $inserts);
        $this->assertContains(' | CHF', $inserts);
        $this->assertNotContains('{{beleg_id}}', $inserts, 'Die Rückverknüpfung der Kind-Tabelle fehlt.');
    }
}
