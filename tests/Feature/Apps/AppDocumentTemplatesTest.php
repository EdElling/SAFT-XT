<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\Apps\Definition\AppDefinition;
use App\Apps\Definition\AppDefinitionValidator;
use App\Apps\Definition\AppInstaller;
use App\Apps\Update\ChangeExecutor;
use App\Apps\Update\ChangePlanner;
use App\Apps\Update\ChangeStep;
use App\Documents\Templates\TemplateChecker;
use App\Models\App;
use App\Models\AppBaseline;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use Database\Seeders\GitarrenshopDemoSeeder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Dokumentvorlagen in der App-Definition (B-124, D1d): Export, Prüfung, Installation als Kopie, Aktualisieren. */
class AppDocumentTemplatesTest extends DynamicDataTestCase
{
    private App $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        Storage::fake('local');
        $this->seed(GitarrenshopDemoSeeder::class);
        $this->shop = CustomTable::query()->where('name', 'gitarren_belege')->sole()->app;
    }

    /** @return array<string, mixed> */
    private function export(array $parts = AppDefinition::PARTS): array
    {
        return app(AppDefinition::class)->build($this->shop, $parts, $this->superAdmin());
    }

    public function test_export_contains_the_templates_without_letterhead(): void
    {
        $definition = $this->export();

        $this->assertContains('documents', $definition['parts']);
        $this->assertSame([[
            'key' => GitarrenshopDemoSeeder::INVOICE_TEMPLATE, 'name' => 'Rechnung', 'table' => 'gitarren_belege', 'file_name' => 'Rechnung-{{belegnummer}}',
            'language_field' => null, 'file_field' => 'dateien', 'email_to' => '{{kunde_id.email}}', 'email_subject' => 'Rechnung {{belegnummer}}',
            'email_reply_to' => null, 'formats' => 'both', 'is_active' => true, 'sort_order' => 0,
        ]], array_map(static fn (array $d): array => array_diff_key($d, array_flip(['recipient', 'body', 'email_body', 'qr_bill'])), $definition['documents']));
        $this->assertStringContainsString('{{#gitarren_buchungen}}', $definition['documents'][0]['body']);
        $this->assertSame(['enabled' => true, 'reference' => '{{belegnummer}}'], array_intersect_key($definition['documents'][0]['qr_bill'], array_flip(['enabled', 'reference'])), 'QR-Rechnung (D4a) geht mit.');
        $this->assertArrayNotHasKey('letterhead', $definition);
        $this->assertSame([], app(AppDefinitionValidator::class)->errors($definition));
        $this->assertSame([], $this->export(['reports'])['documents'], 'Ohne den Teil keine Vorlagen.');

        $broken = $definition;
        $broken['documents'][0]['table'] = 'gibtsnicht';
        $broken['documents'][1] = [...$definition['documents'][0], 'key' => 'a8e2d4f6-1b3c-4d5e-8f9a-0b1c2d3e4f5a', 'file_field' => 'fehlt'];
        $errors = app(AppDefinitionValidator::class)->errors($broken);
        $this->assertStringContainsString('documents[0].table', implode(' ', $errors));
        $this->assertStringContainsString('documents[1].file_field', implode(' ', $errors));
    }

    public function test_installs_a_copy_with_renamed_tables_in_sections(): void
    {
        $installer = app(AppInstaller::class);
        $analysis = $installer->analyze($this->export(), ['name' => 'Gitarren Zweig', 'prefix' => 'gz']);
        $this->assertSame([], $analysis->blockers);
        $this->assertSame(1, $analysis->counts['documents']);

        $copy = $installer->install($this->export(), ['name' => 'Gitarren Zweig', 'prefix' => 'gz'], $this->superAdmin());
        $template = DocumentTemplate::query()->where('app_id', $copy->id)->sole();

        $this->assertNotSame(GitarrenshopDemoSeeder::INVOICE_TEMPLATE, $template->uuid, 'Kopie mit neuen Schlüsseln.');
        $this->assertSame('gz_belege', $template->customTable->name);
        $this->assertStringContainsString('{{#gz_buchungen}}', $template->body);
        $this->assertStringContainsString('{{/gz_zahlungen}}', $template->body);
        $this->assertStringContainsString('{{#artikel_id}}', $template->body, 'Felder behalten ihren Namen.');
        $this->assertSame([], app(TemplateChecker::class)->check($template->customTable, $template->body, $template->recipient, $template->file_name)['errors']);
        $this->assertSame([], $installer->notes());
    }

    public function test_definitions_without_the_part_install_without_templates(): void
    {
        $definition = $this->export(['reports']);
        unset($definition['documents']);

        $copy = app(AppInstaller::class)->install($definition, ['name' => 'Ohne Vorlagen', 'prefix' => 'ov'], $this->superAdmin());

        $this->assertSame(0, DocumentTemplate::query()->where('app_id', $copy->id)->count());
    }

    public function test_update_creates_changes_and_removes_templates_and_reports_conflicts(): void
    {
        Process::fake(static function (PendingProcess $process) {
            foreach ((array) $process->command as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'dump');
                }
            }

            return Process::result(output: "-- dump\n");
        });
        $admin = $this->superAdmin();
        $base = $this->export();
        AppBaseline::query()->create(['app_id' => $this->shop->id, 'kind' => AppBaseline::INSTALLED, 'version' => '1.0', 'definition' => $base, 'installed_by' => $admin->id]);

        $target = $base;
        $target['app']['version'] = '1.1';
        $target['documents'][0]['body'] .= '<p>Neu im Paket</p>';
        $target['documents'][] = [...$base['documents'][0], 'key' => 'c3d4e5f6-a7b8-4c9d-8e0f-1a2b3c4d5e6f', 'name' => 'Lieferschein', 'file_field' => null];
        $plan = app(ChangePlanner::class)->plan($this->shop, $target, $admin);
        $documents = array_values(array_filter($plan->steps, static fn (ChangeStep $s): bool => $s->kind === 'document'));

        $this->assertEqualsCanonicalizing([ChangeStep::UPDATE, ChangeStep::CREATE], array_column($documents, 'action'));
        $this->assertSame([], array_filter($documents, static fn (ChangeStep $s): bool => $s->conflict));

        app(ChangeExecutor::class)->apply($this->shop, $plan, $plan->preselected(), false, $admin);
        $this->assertStringContainsString('Neu im Paket', DocumentTemplate::query()->where('uuid', GitarrenshopDemoSeeder::INVOICE_TEMPLATE)->value('body'));
        $this->assertTrue(DocumentTemplate::query()->where('name', 'Lieferschein')->where('app_id', $this->shop->id)->exists());

        // Lokal geändert und im Paket anders: Konflikt. Im Paket entfernt: Schritt zum Entfernen.
        DocumentTemplate::query()->where('uuid', GitarrenshopDemoSeeder::INVOICE_TEMPLATE)->update(['body' => '<p>Eigene Fassung</p>']);
        $next = $target;
        $next['app']['version'] = '1.2';
        $next['documents'][0]['body'] = '<p>Paket 1.2</p>';
        $next['documents'] = [$next['documents'][0]];
        $plan = app(ChangePlanner::class)->plan($this->shop, $next, $admin);
        $steps = collect($plan->steps)->where('kind', 'document')->keyBy('action');

        $this->assertTrue($steps[ChangeStep::UPDATE]->conflict);
        $this->assertSame('Lieferschein', $steps[ChangeStep::DELETE]->label);
    }
}
