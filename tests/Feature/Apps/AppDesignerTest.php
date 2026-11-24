<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\Apps\CurrentApp;
use App\Apps\Designer\AppDesigner;
use App\Apps\Designer\DesignerResult;
use App\Apps\Designer\DraftCompiler;
use App\Apps\Designer\DraftReports;
use App\Apps\Designer\DraftTranslator;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\AppDesignerPage;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Models\App;
use App\Models\AppDraft;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkItem;
use App\Reports\Assistant\AssistantClient;
use App\Reports\Assistant\AssistantReply;
use App\Settings\AppSettings;
use App\Work\Types\AppDesignerCreateWork;
use App\Work\Types\AppDesignerReportsWork;
use App\Work\WorkRunner;
use App\Work\WorkService;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fakes\FakeAssistantClient;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** KI-App-Designer (B-107, Etappe 7a): Entwurf im Gespräch, geprüft, dann als aktive App mit Fachrolle erzeugt. */
class AppDesignerTest extends DynamicDataTestCase
{
    private User $admin;

    private FakeAssistantClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);

        config(['ai.provider' => 'anthropic', 'ai.daily_limit' => 50]);
        $settings = app(AppSettings::class);
        $settings->ai_assistant_enabled = true;
        $settings->save();

        $this->fake = new FakeAssistantClient;
        $this->app->instance(AssistantClient::class, $this->fake);
    }

    protected function tearDown(): void
    {
        DraftTranslator::$chunk = 120;
        parent::tearDown();
    }

    /** App aus dem Entwurf erzeugen – als Arbeit bis zum Ende (Fundament 2, F3b), wie die Seite sie startet. */
    private function createApp(AppDraft $draft, ?string $name = null, ?string $prefix = null, bool|array $reports = true): WorkItem
    {
        return app(WorkService::class)->startAndDrive(AppDesignerCreateWork::KEY, array_filter(['draft_id' => $draft->id, 'name' => $name, 'prefix' => $prefix, 'reports' => $reports], static fn (mixed $v): bool => $v !== null), $this->admin);
    }

    /** Die Seite hat eine Arbeit gestartet: zu Ende treiben (wie der Browser) und die Seite nachfragen lassen. */
    private function finish(Testable $page): Testable
    {
        foreach (WorkItem::query()->whereIn('status', WorkItem::OPEN)->orderBy('id')->get() as $item) {
            app(WorkService::class)->drive($item);
        }

        return $page->call('poll');
    }

    /** Nur das Erzeugen zu Ende treiben (die Auswertungen bleiben für die Prüfung je Schritt), dann nachfragen. */
    private function finishCreation(Testable $page): Testable
    {
        foreach (WorkItem::query()->where('type', AppDesignerCreateWork::KEY)->whereIn('status', WorkItem::OPEN)->get() as $item) {
            app(WorkService::class)->drive($item);
        }

        return $page->call('poll');
    }

    /** @return array<string, string> */
    private function t(string $de, ?string $en = null): array
    {
        return ['de' => $de, 'en' => $en ?? $de, 'fr' => $en ?? $de, 'it' => $en ?? $de];
    }

    /** @return array<string, mixed> */
    private function field(string $name, string $type, array $overrides = []): array
    {
        return [...[
            'name' => $name, 'label' => $this->t(ucfirst($name)), 'type' => $type,
            'required' => false, 'unique' => false, 'searchable' => false, 'sortable' => false,
            'length' => 0, 'precision' => 0, 'scale' => 0, 'choices' => [], 'relation_table' => '', 'relation_display' => [],
            'file_kinds' => [], 'max_mb' => 0, 'max_files' => 0, 'rules' => '', 'formula' => '',
        ], ...$overrides];
    }

    /** Kursverwaltung: Kurse, Teilnehmende, Anmeldungen (Kind von beiden), Summen und eine Regel. */
    private function courses(array $overrides = []): array
    {
        return [...[
            'kind' => 'app', 'question' => '',
            'app' => ['name' => $this->t('Kurse', 'Courses'), 'description' => $this->t('Kurse und Anmeldungen'), 'prefix' => 'kurse', 'icon' => 'academic-cap'],
            'tables' => [
                ['name' => 'kurse_teilnehmende', 'label' => $this->t('Teilnehmende'), 'description' => $this->t(''), 'icon' => 'users', 'rules' => [], 'fields' => [
                    $this->field('name', 'string', ['required' => true, 'searchable' => true]),
                    $this->field('email', 'string', ['rules' => 'email']),
                ]],
                ['name' => 'kurse_kurse', 'label' => $this->t('Kurse'), 'description' => $this->t(''), 'icon' => 'academic-cap', 'rules' => ['preis >= 0'], 'fields' => [
                    $this->field('titel', 'string', ['required' => true]),
                    $this->field('preis', 'decimal', ['precision' => 10, 'scale' => 2]),
                    // Summen über die Kind-Tabelle des Entwurfs, die es noch nicht gibt.
                    $this->field('anmeldungen', 'computed', ['formula' => 'count(kurse_anmeldungen)', 'precision' => 6, 'scale' => 0]),
                    $this->field('einnahmen', 'computed', ['formula' => 'sum(if(kurse_anmeldungen.bezahlt, kurse_anmeldungen.betrag, 0))', 'precision' => 12, 'scale' => 2]),
                ]],
                ['name' => 'kurse_anmeldungen', 'label' => $this->t('Anmeldungen'), 'description' => $this->t(''), 'icon' => 'clipboard-document-list', 'rules' => [], 'fields' => [
                    $this->field('kurs_id', 'relation', ['relation_table' => 'kurse_kurse', 'relation_display' => ['titel'], 'required' => true]),
                    $this->field('person_id', 'relation', ['relation_table' => 'kurse_teilnehmende', 'relation_display' => ['name'], 'required' => true]),
                    $this->field('betrag', 'decimal', ['precision' => 10, 'scale' => 2]),
                    $this->field('bezahlt', 'boolean'),
                ]],
            ],
            'menus' => [
                ['label' => $this->t('Kursbetrieb'), 'icon' => 'calendar', 'tables' => ['kurse_kurse', 'kurse_anmeldungen']],
                ['label' => $this->t('Stammdaten'), 'icon' => 'archive-box', 'tables' => ['kurse_teilnehmende']],
            ],
            'reports' => [
                ['title' => 'Einnahmen je Kurs', 'description' => 'Kurse mit der Summe der bezahlten Einnahmen, als Balken.'],
                ['title' => 'Anmeldungen je Kurs', 'description' => 'Anzahl Anmeldungen je Kurs.'],
            ],
        ], ...$overrides];
    }

    public function test_a_draft_is_proposed_checked_and_corrected_in_conversation(): void
    {
        $designer = app(AppDesigner::class);
        $draft = $designer->start($this->admin);
        $broken = $this->courses();
        $broken['tables'][1]['fields'][3]['formula'] = 'sum(kurse_anmeldungen.titel)';

        $this->fake->push($broken)->push($this->courses());
        $result = $designer->propose($this->admin, $draft, 'Ich brauche eine Kursverwaltung mit Anmeldungen und Einnahmen.');

        $this->assertSame(DesignerResult::PROPOSAL, $result->status);
        $this->assertStringContainsString('Unbekanntes Feld „titel“ in „kurse_anmeldungen“', end($this->fake->requests[1]['messages'])['content'], 'Korrekturrunde mit der Meldung der Formelprüfung.');
        $this->assertStringContainsString('"existing_prefixes"', $this->fake->requests[0]['system'][1]['text']);
        $this->assertFalse($this->fake->requests[0]['strict'], 'Ohne erzwungene Form (Grammatik zu gross).');

        $draft->refresh();
        $this->assertSame('Ich brauche eine Kursverwaltung mit Anmeldungen und Einnahmen.', $draft->title);
        $this->assertSame(['kurse_teilnehmende', 'kurse_kurse', 'kurse_anmeldungen'], array_column($draft->definition['tables'], 'name'));
        $this->assertSame(['kurse_anmeldungen.kurs_id'], $draft->definition['tables'][1]['details'], 'Detailtabelle aus der Verknüpfung.');
        $history = (array) $draft->history;
        $this->assertSame('Proposal with the tables: kurse_teilnehmende, kurse_kurse, kurse_anmeldungen.', end($history)['content'], 'Frühere Vorschläge nur als Kurzfassung.');
        $this->assertSame(0, CustomTable::query()->where('name', 'like', 'kurse_%')->count(), 'Noch nichts in der Datenbank.');

        // Folgefrage: Der aktuelle Entwurf geht mit.
        $this->fake->push(['kind' => 'question', 'question' => 'Sollen Kurse mehrere Termine haben?', 'app' => $this->courses()['app'], 'tables' => [], 'menus' => []]);
        $this->assertSame(DesignerResult::QUESTION, $designer->propose($this->admin, $draft, 'Bitte Termine ergänzen.')->status);
        $this->assertStringContainsString('"kurse_anmeldungen"', end($this->fake->requests[2]['messages'])['content']);
        $this->assertSame(3, $draft->refresh()->tableCount(), 'Eine Rückfrage ändert den Entwurf nicht.');
    }

    public function test_the_compiler_finds_problems_before_anyone_sees_the_draft(): void
    {
        $compiler = app(DraftCompiler::class);
        $this->service()->createTable(['name' => 'kurse_kurse', 'label' => 'Schon da'], [['name' => 'x', 'label' => 'X', 'type' => 'string']]);

        $draft = $this->courses();
        $draft['tables'][0]['name'] = 'teilnehmende';
        $draft['menus'][1]['tables'][] = 'kurse_fehlt';
        $draft['tables'][2]['fields'][1]['relation_table'] = 'gibt_es_nicht';
        $problems = implode("\n", $compiler->compile($draft)['problems']);

        foreach ([
            'Die Tabelle „teilnehmende“ muss mit dem Präfix „kurse_“ beginnen.',
            '„kurse_kurse“ ist schon vergeben',
            'Die Menügruppe nennt die Tabelle „kurse_fehlt“',
            'gibt_es_nicht',
        ] as $expected) {
            $this->assertStringContainsString($expected, $problems);
        }

        App::query()->create(['uuid' => (string) Str::uuid(), 'name' => ['de' => 'Andere'], 'prefix' => 'kurse', 'sort_order' => 5]);
        $this->assertStringContainsString('Das Präfix „kurse“ verwendet schon eine andere App.', implode(' ', $compiler->compile($this->courses())['problems']));
    }

    public function test_create_installs_an_active_app_with_menus_role_and_working_formulas(): void
    {
        $designer = app(AppDesigner::class);
        $draft = $designer->start($this->admin);
        $this->fake->push($this->courses());
        $designer->propose($this->admin, $draft, 'Kursverwaltung');

        $item = $this->createApp($draft->refresh());
        $this->assertSame([WorkItem::DONE, 'App „Kurse“ erzeugt.', 2, 2], [$item->status, $item->message, $item->done, $item->total]);
        $app = App::query()->findOrFail((int) $item->result['app_id']);

        $this->assertTrue($app->is_active);
        $this->assertSame($app->id, app(CurrentApp::class)->id($this->admin->refresh()), 'Gleich in der neuen App.');
        $this->assertSame([AppDraft::CREATED, $app->id], [$draft->refresh()->status, $draft->app_id]);

        $courses = CustomTable::query()->where('name', 'kurse_kurse')->sole();
        $this->assertSame($app->id, $courses->app_id);
        $this->assertCount(1, $courses->tableRules());

        // Menü: Gruppe der App, zwei Untergruppen, Datenmodell; Fachrolle mit allen Rechten.
        $root = Menu::query()->whereNull('parent_id')->where('app_id', $app->id)->sole();
        $this->assertSame(['Kursbetrieb', 'Stammdaten', 'Datenmodell'], Menu::query()->where('parent_id', $root->id)->orderBy('sort_order')->get()->map(fn (Menu $m): string => $m->getTranslation('label', 'de'))->all());
        $role = Role::query()->where('app_id', $app->id)->sole();
        $this->assertTrue($role->hasPermissionTo('data.kurse_anmeldungen.delete') && $role->hasPermissionTo('reports.view'));

        // Berechnete Felder über die Kind-Tabelle rechnen.
        $course = DynamicRecord::forTableName('kurse_kurse')->create(['titel' => 'Gitarre für Anfänger', 'preis' => '120.00']);
        $person = DynamicRecord::forTableName('kurse_teilnehmende')->create(['name' => 'Lena']);
        DynamicRecord::forTableName('kurse_anmeldungen')->create(['kurs_id' => $course->id, 'person_id' => $person->id, 'betrag' => '120.00', 'bezahlt' => true]);
        DynamicRecord::forTableName('kurse_anmeldungen')->create(['kurs_id' => $course->id, 'person_id' => $person->id, 'betrag' => '120.00', 'bezahlt' => false]);
        $course = DynamicRecord::forTableName('kurse_kurse')->newQuery()->findOrFail($course->id);
        $this->assertSame([2, '120.00'], [(int) $course->anmeldungen, (string) $course->einnahmen]);

        // Noch einmal: der Entwurf ist schon erzeugt.
        $again = $this->createApp($draft->refresh());
        $this->assertSame([WorkItem::FAILED, 'Es gibt noch keinen Entwurf, aus dem sich eine App erzeugen lässt.'], [$again->status, $again->message]);
    }

    public function test_create_with_another_name_and_prefix_and_availability(): void
    {
        $designer = app(AppDesigner::class);
        $draft = $designer->start($this->admin);
        $this->fake->push($this->courses());
        $designer->propose($this->admin, $draft, 'Kursverwaltung');

        $app = App::query()->findOrFail((int) $this->createApp($draft->refresh(), 'Musikschule', 'musik')->result['app_id']);
        $this->assertSame(['Musikschule', 'musik'], [$app->getTranslation('name', 'de'), rtrim((string) $app->prefix, '_')]);
        $this->assertTrue(CustomTable::query()->where('name', 'musik_kurse')->exists(), 'Ein anderes Präfix benennt die Tabellen um.');
        $this->assertFalse(CustomTable::query()->where('name', 'kurse_kurse')->exists());

        // Ohne Recht oder ohne KI kein Designer; fremde Entwürfe gehen nicht.
        $this->assertFalse(AppDesigner::availableFor($this->userWithPermissions(['apps.view'])));
        $other = $this->userWithPermissions(['apps.view', 'apps.design']);
        $this->assertTrue(AppDesigner::availableFor($other));
        $this->expectException(BusinessRuleException::class);
        $designer->propose($other, $designer->start($this->admin), 'Fremder Entwurf');
    }

    public function test_the_page_shows_conversation_preview_and_creates_the_app(): void
    {
        $this->fake->push($this->courses());
        $page = Livewire::test(AppDesignerPage::class)
            ->assertSee(__('app_designer.intro'))
            ->assertActionHidden('createApp')
            ->set('prompt', 'Kursverwaltung mit Anmeldungen')
            ->call('send')
            ->assertSet('prompt', '')
            // Die Runde läuft als Arbeit: Fortschritt statt Eingabe, bis sie zu Ende ist.
            ->assertSeeHtml('data-designer-working')
            ->assertSee('Die KI entwirft');
        $this->assertSame(1, WorkItem::query()->where('type', 'app_designer_turn')->whereIn('status', WorkItem::OPEN)->count());
        $page = $this->finish($page)
            ->assertSet('workId', null)
            ->assertSee('Kursverwaltung mit Anmeldungen')
            ->assertSee('Vorschlag mit den Tabellen kurse_teilnehmende, kurse_kurse, kurse_anmeldungen – siehe Vorschau.')
            ->assertSee('= sum(if(kurse_anmeldungen.bezahlt, kurse_anmeldungen.betrag, 0))')
            ->assertSee('→ kurse_kurse')
            ->assertSee('3 Tabellen, 10 Felder')
            ->assertActionVisible('createApp');

        $page->mountAction('createApp')
            ->assertSchemaStateSet(['name' => 'Kurse', 'prefix' => 'kurse', 'reports' => [0, 1]])
            // Alle Auswertungen abgewählt: gleich in die App.
            ->setActionData(['reports' => []])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSee('Die App wird erzeugt');
        $this->finish($page)
            ->assertNotified('App „Kurse“ erzeugt.')
            ->assertRedirect(Dashboard::getUrl());
        $this->assertTrue(App::query()->where('prefix', 'like', 'kurse%')->sole()->is_active);

        // Entwurf nach dem Erzeugen: Hinweis statt Eingabe; löschen entfernt nur den Entwurf.
        $draft = AppDraft::query()->sole();
        Livewire::test(AppDesignerPage::class, ['draft' => $draft->id])
            ->assertSee('Aus diesem Entwurf wurde die App „Kurse“ erzeugt.')
            ->assertActionHidden('createApp')
            ->call('deleteDraft', $draft->id);
        $this->assertSame(0, AppDraft::query()->count());
        $this->assertTrue(CustomTable::query()->where('name', 'kurse_kurse')->exists());
    }

    public function test_page_access_errors_and_the_button_on_the_apps_list(): void
    {
        Livewire::test(ListApps::class)->assertActionVisible('designApp');

        // KI-Fehler (zweimal ungültig) als Meldung unter der Eingabe; die Eingabe bleibt stehen.
        $broken = $this->courses(['tables' => []]);
        $this->fake->push($broken)->push($broken);
        $page = Livewire::test(AppDesignerPage::class)
            ->set('prompt', 'Leer')
            ->call('send');
        $this->finish($page)
            ->assertSet('prompt', 'Leer')
            ->assertSee('Kein gültiger Entwurf: Der Entwurf hat keine Tabellen.');

        // Fremde Entwürfe sind nicht zu sehen; ohne Recht keine Seite.
        $other = $this->userWithPermissions(['apps.view', 'apps.design']);
        $foreign = AppDraft::query()->latest('id')->firstOrFail();
        $this->actingAs($other);
        Livewire::test(AppDesignerPage::class, ['draft' => $foreign->id])->assertDontSee('Leer');
        $this->actingAs($this->userWithPermissions(['apps.view']));
        $this->get(AppDesignerPage::getUrl())->assertForbidden();
        Livewire::test(ListApps::class)->assertActionHidden('designApp');
    }

    public function test_the_diagram_is_replaced_when_the_draft_changes(): void
    {
        $more = $this->courses();
        $more['tables'][] = ['name' => 'kurse_raeume', 'label' => $this->t('Räume'), 'description' => $this->t(''), 'icon' => 'home', 'rules' => [], 'fields' => [$this->field('name', 'string')]];
        $this->fake->push($this->courses())->push($more);

        $key = static fn (string $html): string => (string) preg_replace('/.*data-designer-diagram="([0-9a-f]+)".*/s', '$1', $html);
        $page = $this->finish(Livewire::test(AppDesignerPage::class)->set('prompt', 'Kurse')->call('send'));
        $before = $key($page->html());
        $this->finish($page->set('prompt', 'Räume ergänzen')->call('send'));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $before);
        $this->assertNotSame($before, $key($page->html()), 'Neuer Schlüssel: Livewire ersetzt das Diagramm.');
    }

    /** Antwort des Auswertungsassistenten für die erzeugte App (echte IDs). */
    private function report(string $title, string $base, array $rows, array $values, string $visual = 'bar'): array
    {
        $ref = static fn (string $qualified): string => ':'.(int) CustomField::query()->whereHas('custom_table', fn ($q) => $q->where('name', explode('.', $qualified)[0]))->where('name', explode('.', $qualified)[1])->value('id');

        return [
            'kind' => 'report', 'question' => null, 'title' => $title,
            'base' => (int) CustomTable::query()->where('name', $base)->value('id'),
            'rows' => array_map(static fn (string $f): array => ['ref' => $ref($f), 'bucket' => null], $rows),
            'columns' => [],
            'values' => array_map(static fn (array $v): array => ['agg' => $v[0], 'ref' => $v[1] === null ? null : $ref($v[1])], $values),
            'filters' => [], 'sort' => null, 'limit' => null, 'visual' => $visual,
        ];
    }

    public function test_reports_are_created_one_by_one_after_the_app(): void
    {
        $this->fake->push($this->courses());
        $page = $this->finish(Livewire::test(AppDesignerPage::class)->set('prompt', 'Kursverwaltung')->call('send'))
            ->assertSee('Auswertungen (werden nach dem Erzeugen angelegt)')
            ->assertSee('Einnahmen je Kurs');

        $page->mountAction('createApp')
            ->assertSchemaStateSet(['reports' => [0, 1]])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        // Erzeugt: die Seite bleibt, startet die Arbeit für die Auswertungen und zeigt den Fortschritt.
        $this->finishCreation($page)
            ->assertNoRedirect()
            ->assertSee('⏳ wird erstellt …')
            ->assertSee('Zur neuen App');
        $draft = AppDraft::query()->sole();
        $this->assertSame([DraftReports::PENDING, DraftReports::PENDING], array_column($draft->reports, 'status'));
        $work = WorkItem::query()->where('type', AppDesignerReportsWork::KEY)->sole();
        $this->assertSame([WorkItem::QUEUED, $work->id], [$work->status, $page->get('reportsWorkId')]);

        // Je Schritt (langer Schritt: genau einer je Aufruf) eine Auswertung: die erste gelingt, die zweite kommt als Rückfrage zurück.
        $runner = app(WorkRunner::class);
        $this->fake->push($this->report('Einnahmen je Kurs', 'kurse_anmeldungen', ['kurse_anmeldungen.kurs_id'], [['sum', 'kurse_anmeldungen.betrag']]));
        $work = $runner->advance($work, 30);
        $this->assertSame([WorkItem::RUNNING, 1, 2], [$work->status, $work->done, $work->total]);
        $this->fake->push(['kind' => 'question', 'question' => 'Welcher Zeitraum?', 'title' => '', 'base' => null, 'rows' => [], 'columns' => [], 'values' => [], 'filters' => [], 'sort' => null, 'limit' => null, 'visual' => 'table']);
        $work = $runner->advance($work, 30);
        $this->assertSame([WorkItem::DONE, '1 Auswertung angelegt. 1 nicht möglich.'], [$work->status, $work->message]);

        $draft->refresh();
        $this->assertSame([DraftReports::CREATED, DraftReports::FAILED], array_column($draft->reports, 'status'));
        $this->assertSame('Welcher Zeitraum?', $draft->reports[1]['message']);
        $report = Report::query()->findOrFail($draft->reports[0]['report_id']);
        $this->assertTrue($report->is_shared);
        $this->assertSame($draft->app_id, $report->app_id, 'Gehört zur neuen App.');
        $group = Menu::query()->where('app_id', $draft->app_id)->where('label->de', 'Auswertungen')->sole();
        $this->assertSame(['Einnahmen je Kurs'], Menu::query()->where('parent_id', $group->id)->get()->map(fn (Menu $m): string => $m->getTranslation('label', 'de'))->all());

        // Nicht möglich: Grund und „Erneut versuchen“; das startet eine neue Arbeit, danach gelingt sie.
        $page->call('poll')->assertSet('reportsWorkId', null)->assertSee('nicht möglich: Welcher Zeitraum?')->assertSee('Erneut versuchen')
            ->call('retryReport', 1);
        $retry = WorkItem::query()->where('type', AppDesignerReportsWork::KEY)->latest('id')->first();
        $this->assertNotSame($work->id, $retry->id);
        $this->fake->push($this->report('Anmeldungen je Kurs', 'kurse_anmeldungen', ['kurse_anmeldungen.kurs_id'], [['count', null]], 'table'));
        $this->assertSame(WorkItem::DONE, $runner->advance($retry, 30)->status);
        $this->assertSame([DraftReports::CREATED, DraftReports::CREATED], array_column($draft->refresh()->reports, 'status'));
        $this->assertSame(2, Report::query()->where('app_id', $draft->app_id)->count());
    }

    /** Ohne KI (Testserver, Anbieter weg) muss der Fortschritt einer laufenden Arbeit trotzdem erscheinen – er treibt sie. */
    public function test_the_progress_of_a_running_creation_shows_even_without_ai(): void
    {
        $this->fake->push($this->courses());
        $page = $this->finish(Livewire::test(AppDesignerPage::class)->set('prompt', 'Kursverwaltung')->call('send'))
            ->mountAction('createApp')->setActionData(['reports' => []])->callMountedAction();

        config(['ai.provider' => null]);
        $this->assertFalse(AppDesigner::availableFor($this->admin));
        Livewire::test(AppDesignerPage::class, ['draft' => AppDraft::query()->sole()->id])
            ->assertSet('workId', $page->get('workId'))
            ->assertSeeHtml('data-designer-working')
            ->assertDontSeeHtml('data-designer-unavailable');
    }

    public function test_proposed_reports_can_be_deselected_one_by_one(): void
    {
        $this->fake->push($this->courses());
        $page = $this->finish(Livewire::test(AppDesignerPage::class)->set('prompt', 'Kursverwaltung')->call('send'))
            ->mountAction('createApp')
            // Nur die zweite Auswertung anlegen.
            ->setActionData(['reports' => [1]])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->finishCreation($page)->assertNoRedirect();

        $this->assertSame(['Anmeldungen je Kurs'], array_column(AppDraft::query()->sole()->reports, 'title'));
    }

    public function test_a_truncated_answer_gives_a_clear_message_without_a_correction_round(): void
    {
        config(['ai.max_tokens' => 16000, 'ai.timeout' => 120]);
        $this->fake->push(new AssistantReply('{"kind": "app", "app": {"name": {"de": "Kur', 'max_tokens', inputTokens: 5000, outputTokens: 32000));

        $result = app(AppDesigner::class)->propose($this->admin, app(AppDesigner::class)->start($this->admin), 'Eine riesige App');

        $this->assertSame(DesignerResult::ERROR, $result->status);
        $this->assertStringStartsWith('Der Entwurf ist zu umfangreich für eine Antwort der KI (höchstens 32000 Token).', (string) $result->message);
        $this->assertCount(1, $this->fake->requests, 'Keine Korrekturrunde – sie würde wieder zu lang.');
        // Für den Designer gelten eigene, höhere Grenzen.
        $this->assertSame([32000, 900], [config('ai.max_tokens'), config('ai.timeout')]);
    }

    /** Vorschlag nur in der Sprache der Person (wie im Gespräch): alle anderen Sprachen leer. */
    private function german(array $value): array
    {
        if (array_keys($value) === ['de', 'en', 'fr', 'it']) {
            return ['de' => $value['de'], 'en' => '', 'fr' => '', 'it' => ''];
        }

        return array_map(fn (mixed $v): mixed => is_array($v) ? $this->german($v) : $v, $value);
    }

    /** Übersetzung durch die Attrappe: „EN …“, „FR …“, „IT …“ je Text der Anfrage. */
    private function translation(): \Closure
    {
        return static function (array $messages): array {
            $texts = json_decode(substr((string) end($messages)['content'], strpos((string) end($messages)['content'], 'Texts: ') + 7), true);

            return ['translations' => array_map(static fn (string $id, string $text): array => ['id' => $id, 'de' => $text, 'en' => 'EN '.$text, 'fr' => 'FR '.$text, 'it' => 'IT '.$text], array_keys($texts), $texts)];
        };
    }

    public function test_labels_are_drafted_in_one_language_and_translated_when_creating(): void
    {
        DraftTranslator::$chunk = 10;
        $this->fake->push($this->german($this->courses()));
        $designer = app(AppDesigner::class);
        $draft = $designer->start($this->admin);
        $designer->propose($this->admin, $draft, 'Kursverwaltung');
        $this->assertStringContainsString('ONLY in the language of the user', $this->fake->requests[0]['system'][0]['text']);
        $this->assertSame('', $draft->refresh()->definition['tables'][1]['label']['en'] ?? '', 'Im Entwurf nur Deutsch.');

        // Beim Erzeugen: in Teilen zu höchstens 10 Texten übersetzt (gleiche Texte einmal).
        foreach (range(1, 5) as $i) {
            $this->fake->push($this->translation());
        }
        $item = $this->createApp($draft);
        $app = App::query()->findOrFail((int) $item->result['app_id']);
        $translations = array_slice($this->fake->requests, 1);
        $this->assertGreaterThan(1, count($translations), 'Mehrere Teile.');
        $this->assertNull($item->result['warning']);

        $courses = CustomTable::query()->where('name', 'kurse_kurse')->sole();
        $this->assertSame(['Kurse', 'EN Kurse', 'IT Kurse'], [$courses->getTranslation('label', 'de'), $courses->getTranslation('label', 'en'), $courses->getTranslation('label', 'it')]);
        $this->assertSame('FR Titel', $courses->fields()->where('name', 'titel')->sole()->getTranslation('label', 'fr'));
        $this->assertSame('EN Kurse', $app->getTranslation('name', 'en'));
        $this->assertSame('IT Kursbetrieb', Menu::query()->where('app_id', $app->id)->where('label->de', 'Kursbetrieb')->sole()->getTranslation('label', 'it'));
    }

    public function test_the_app_is_created_untranslated_with_a_warning_when_translating_fails(): void
    {
        $this->fake->push($this->german($this->courses()));
        $designer = app(AppDesigner::class);
        $draft = $designer->start($this->admin);
        $designer->propose($this->admin, $draft, 'Kursverwaltung');

        // Zweimal unvollständig: kein weiterer Versuch, die App entsteht trotzdem.
        $this->fake->push(['translations' => []])->push(['translations' => []]);
        $item = $this->createApp($draft);
        $app = App::query()->findOrFail((int) $item->result['app_id']);

        $this->assertStringStartsWith('Die Bezeichnungen konnten nicht übersetzt werden', (string) $item->result['warning']);
        $this->assertTrue($app->is_active);
        $this->assertSame('Kurse', CustomTable::query()->where('name', 'kurse_kurse')->sole()->getTranslation('label', 'en'), 'Ohne Übersetzung gilt die Vorgabesprache.');
    }
}
