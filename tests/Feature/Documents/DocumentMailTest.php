<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Documents\DocumentBatch;
use App\Documents\DocumentGenerator;
use App\Documents\DocumentMailer;
use App\Documents\Templates\TemplateRenderer;
use App\DynamicData\DataPermissions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Mail\DocumentMail;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkItem;
use App\Work\Types\DocumentsWork;
use App\Work\WorkRunner;
use App\Work\WorkService;
use Database\Seeders\GitarrenshopDemoSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Dokumente per E-Mail versenden (B-124, D3b) – mit Mail::fake(), ohne echten Versand. */
class DocumentMailTest extends DynamicDataTestCase
{
    private CustomTable $receipts;

    private DocumentTemplate $template;

    private DynamicRecord $receipt;

    private string $customerEmail;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        Storage::fake('local');
        Mail::fake();
        $this->seed(GitarrenshopDemoSeeder::class);
        $this->receipts = CustomTable::query()->where('name', 'gitarren_belege')->sole();
        $this->template = DocumentTemplate::query()->where('uuid', GitarrenshopDemoSeeder::INVOICE_TEMPLATE)->sole();
        $this->receipt = DynamicRecord::forTableName('gitarren_belege')->newQuery()->where('belegnummer', 'V-2025-001')->sole();
        $this->customerEmail = (string) DynamicRecord::forTableName('gitarren_kunden')->newQuery()->findOrFail($this->receipt->kunde_id)->email;
    }

    private function clerk(bool $send, array $actions = ['view', 'update']): User
    {
        $role = Role::query()->create(['name' => 'kasse-'.uniqid(), 'guard_name' => 'web', 'app_id' => $this->receipts->app_id]);
        $role->givePermissionTo([
            DocumentGenerator::PERMISSION,
            ...($send ? [DocumentMailer::PERMISSION] : []),
            ...array_map(fn (string $a): string => DataPermissions::name('gitarren_belege', $a), $actions),
            // Die Adresse kommt über die Verknüpfung zum Kunden: Platzhalter zeigen nur, was die Person lesen darf.
            DataPermissions::name('gitarren_kunden', 'view'),
        ]);
        $user = $this->userWithPermissions();
        $user->forceFill(['email' => 'kasse-'.uniqid().'@example.ch'])->save();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_template_resolves_address_subject_and_text_in_the_document_language(): void
    {
        $this->assertNotSame('', $this->customerEmail);
        $mail = app(TemplateRenderer::class)->mail($this->template, $this->receipt, $this->superAdmin());

        $this->assertSame([[$this->customerEmail], []], [$mail['to'], $mail['invalid']]);
        $this->assertSame('Rechnung V-2025-001', $mail['subject']);
        $this->assertStringContainsString('Im Anhang erhalten Sie die Rechnung V-2025-001', $mail['body']);
        $this->assertSame([['a@example.ch', 'b@example.ch'], ['kaputt']], TemplateRenderer::addresses("a@example.ch; b@example.ch,\nkaputt a@example.ch"));
    }

    public function test_single_document_is_sent_with_edited_values_copy_and_log(): void
    {
        $user = $this->clerk(true);
        $this->actingAs($user);

        Livewire::test(DynamicDataPage::class, ['table' => 'gitarren_belege'])
            ->mountAction(TestAction::make('documentRow')->table($this->receipt))
            ->assertActionDataSet(['mail_to' => $this->customerEmail, 'mail_subject' => 'Rechnung V-2025-001'])
            ->setActionData(['send' => true, 'mail_to' => 'buchhaltung@example.ch', 'mail_subject' => 'Ihre Rechnung', 'mail_copy' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('E-Mail an buchhaltung@example.ch gesendet');

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('buchhaltung@example.ch') && $mail->hasBcc($user->email)
            && $mail->mailSubject === 'Ihre Rechnung' && $mail->fileName === 'Rechnung-V-2025-001.pdf' && str_starts_with($mail->pdf, '%PDF-'));

        $file = RecordFile::query()->whereNotNull('sha256')->sole();
        $log = Activity::query()->where('event', DocumentMailer::EVENT)->sole();
        $this->assertSame(['buchhaltung@example.ch', 'Ihre Rechnung', $file->sha256, $user->email], [
            $log->attribute_changes['attributes']['an'], $log->attribute_changes['attributes']['betreff'], $log->attribute_changes['attributes']['sha256'], $log->attribute_changes['attributes']['kopie'],
        ]);
    }

    public function test_sending_needs_its_own_permission_and_a_valid_address(): void
    {
        $user = $this->clerk(false);
        $this->actingAs($user);

        $this->assertFalse(DocumentMailer::canSend($user, $this->template));
        Livewire::test(DynamicDataPage::class, ['table' => 'gitarren_belege'])
            ->mountAction(TestAction::make('documentRow')->table($this->receipt))
            ->assertFormFieldDoesNotExist('send');

        $sender = $this->clerk(true);
        $this->actingAs($sender);
        Livewire::test(DynamicDataPage::class, ['table' => 'gitarren_belege'])
            ->mountAction(TestAction::make('documentRow')->table($this->receipt))
            ->setActionData(['send' => true, 'mail_to' => 'keine-adresse'])
            ->callMountedAction()
            ->assertHasActionErrors(['mail_to']);

        $this->template->update(['email_to' => null]);
        $this->assertFalse(DocumentMailer::canSend($sender, $this->template->fresh()), 'Ohne „An“ kein Versand.');

        $this->expectException(BusinessRuleException::class);
        app(DocumentMailer::class)->send($this->template->fresh(), $this->receipt, $user, app(TemplateRenderer::class)->render($this->template, $this->receipt, $user), 'x.pdf', 'abc', ['to' => 'a@example.ch', 'subject' => 's', 'body' => 'b']);
    }

    public function test_batch_sends_one_mail_per_record_and_reports_missing_addresses(): void
    {
        $user = $this->clerk(true);
        $this->actingAs($user);
        $records = DynamicRecord::forTableName('gitarren_belege')->newQuery()->whereNotNull('kunde_id')->orderBy('id')->limit(2)->get();
        $missing = DynamicRecord::forTableName('gitarren_kunden')->newQuery()->findOrFail($records[1]->kunde_id);
        $missing->email = null;
        $missing->save();

        $parameters = ['template_id' => $this->template->id, 'record_ids' => $records->modelKeys(), 'store' => false, 'format' => DocumentBatch::PDF, 'send' => true, 'copy' => false, 'document' => DocumentTemplate::FORMAT_PDF, 'file' => 'Rechnungen.pdf'];
        $runner = app(WorkRunner::class);

        // Ein Dokument je Schritt (Budget 0): Die E-Mail geht nach dem Commit des Schritts, nicht erst am Ende.
        $item = $runner->advance(app(WorkService::class)->start(DocumentsWork::KEY, $parameters, $user), 0);
        $this->assertSame([WorkItem::RUNNING, 1], [$item->status, $item->done]);
        Mail::assertSentCount(1);

        $item = $runner->advance($item, 0);
        Mail::assertSentCount(1);
        $item = $runner->advance($item, 0);

        $this->assertSame([WorkItem::DONE, 2, 2, 1], [$item->status, $item->result['generated'], $item->result['stored'], $item->result['sent']], 'Versendet wird abgelegt, auch ohne „ablegen“.');
        $this->assertCount(1, $item->result['notes']);
        $this->assertStringContainsString('keine E-Mail', $item->result['notes'][0]);
        $this->assertSame('2 Dokumente erstellt, 2 abgelegt, 1 E-Mail gesendet', explode("\n", (string) $item->message)[0]);
        Mail::assertSentCount(1);
    }

    public function test_template_form_checks_placeholders_in_the_mail_parts(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(EditDocumentTemplate::class, ['record' => $this->template->getKey()])
            ->assertFormSet(['email_to' => '{{kunde_id.email}}'])
            ->fillForm(['email_subject' => 'Rechnung {{gibtsnicht}}'])
            ->call('save')
            ->assertHasFormErrors(['body']);
    }
}
