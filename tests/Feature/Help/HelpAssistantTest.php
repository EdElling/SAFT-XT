<?php

declare(strict_types=1);

namespace Tests\Feature\Help;

use App\Ai\ChatClient;
use App\Auth\RoleSimulation;
use App\Documentation\Documentation;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DocumentationPage;
use App\Help\HelpAssistant;
use App\Help\ManualSource;
use App\Livewire\HelpAssistantChat;
use App\Models\AiHelpConversation;
use App\Models\Role;
use App\Models\User;
use App\Settings\AppSettings;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Fakes\FakeChatClient;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Hilfe-Assistent: Verfügbarkeit, Handbücher je Rolle, Quellen, Datenmodell, Speichern, Limit, Oberfläche. */
class HelpAssistantTest extends DynamicDataTestCase
{
    private FakeChatClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => 'anthropic', 'ai.daily_limit' => 50]);
        $settings = app(AppSettings::class);
        $settings->ai_help_enabled = true;
        $settings->ai_help_schema = false;
        $settings->ai_send_choices = true;
        $settings->save();

        $this->fake = new FakeChatClient;
        $this->app->instance(ChatClient::class, $this->fake);
        app()->setLocale('de');
    }

    private function assistant(): HelpAssistant
    {
        return app(HelpAssistant::class);
    }

    /** @param  list<string>  $permissions */
    private function user(array $permissions = ['help.assistant']): User
    {
        return $this->userWithPermissions($permissions);
    }

    private function systemText(int $call = 0): string
    {
        return implode("\n", array_column($this->fake->calls[$call]['system'], 'text'));
    }

    public function test_available_only_when_configured_switched_on_and_permitted(): void
    {
        $this->assertTrue($this->assistant()->availableFor($this->user()));
        $this->assertFalse($this->assistant()->availableFor($this->user([])), 'Ohne Recht.');
        $this->assertFalse($this->assistant()->availableFor(null));

        $settings = app(AppSettings::class);
        $settings->ai_help_enabled = false;
        $settings->save();
        $this->assertFalse(app(HelpAssistant::class)->availableFor($this->user()), 'Ausgeschaltet.');

        $settings->ai_help_enabled = true;
        $settings->save();
        config(['ai.provider' => 'none']);
        $this->assertFalse(app(HelpAssistant::class)->availableFor($this->user()), 'Kein Anbieter.');
    }

    public function test_manuals_follow_the_role_and_anchors_match_the_online_help(): void
    {
        $this->fake->push('A')->push('B');
        $this->assistant()->ask($this->user(), null, 'Wie exportiere ich?');
        $this->assertStringContainsString('<document id="user"', $this->systemText());
        $this->assertStringContainsString('<document id="faq"', $this->systemText());
        $this->assertStringNotContainsString('<document id="admin"', $this->systemText(), 'Ohne Administrationsrecht kein Administratorhandbuch.');
        $this->assertStringNotContainsString('DEVELOPER_GUIDE', $this->systemText());
        $this->assertTrue($this->fake->calls[0]['system'][0]['cache'] ?? false, 'Handbücher werden zwischengespeichert.');

        $this->assistant()->ask($this->user(['help.assistant', 'users.view']), null, 'Wie lege ich Benutzer an?');
        $this->assertStringContainsString('<document id="admin"', $this->systemText(1));

        // Anker wie in der Online-Hilfe (Überschriften der Ebenen 2 und 3).
        $source = new ManualSource('user');
        $this->assertSame(
            array_column(Documentation::sections('user', ['user']), 'id'),
            array_values(array_filter(array_keys($source->anchors()), static fn (string $a): bool => in_array($a, array_column(Documentation::sections('user', ['user']), 'id'), true))),
        );
        $this->assertStringContainsString('{#user-41-die-tabellenansicht}', $source->markdown());
    }

    public function test_answer_is_stored_with_checked_sources_and_logged(): void
    {
        $user = $this->user();
        $this->fake->push("Öffnen Sie die Tabelle und wählen Sie **Export**.\n\nSiehe [4.7 Daten exportieren](#user-47-daten-exportieren) und [Phantasie](#user-gibt-es-nicht).");

        $conversation = $this->assistant()->ask($user, null, 'Wie exportiere ich eine Tabelle?', ['title' => 'Kunden', 'path' => '/admin/data/kunden']);

        $this->assertSame('Wie exportiere ich eine Tabelle?', $conversation->title);
        [$question, $answer] = $conversation->messages()->get()->all();
        $this->assertSame('Wie exportiere ich eine Tabelle?', $question->content, 'Gespeichert ohne den Seitenhinweis.');
        $this->assertStringContainsString('[4.7 Daten exportieren](#user-47-daten-exportieren)', $answer->content);
        $this->assertStringContainsString('und Phantasie.', $answer->content, 'Unbekannter Anker wird zu Text.');
        // assertEquals: MySQL speichert JSON mit umsortierten Schlüsseln.
        $this->assertEquals([['source' => 'user', 'anchor' => 'user-47-daten-exportieren', 'title' => '4.7 Daten exportieren']], $answer->sources);
        $this->assertSame([1200, 150, 1000, 'fake-model'], [$answer->tokens_in, $answer->tokens_out, $answer->tokens_cache, $answer->model]);
        $this->assertStringStartsWith('[Current page: Kunden (/admin/data/kunden)]', $this->fake->calls[0]['messages'][0]['content']);

        $html = $this->assistant()->html($user, $answer->content);
        $this->assertStringContainsString('href="'.e(DocumentationPage::getUrl().'#user-47-daten-exportieren').'" data-help-source', $html);
        $this->assertStringContainsString('href="#user-47-daten-exportieren" data-doc-link', $this->assistant()->html($user, $answer->content, onDocumentationPage: true));

        $log = Activity::query()->where('event', 'assistant')->where('log_name', 'help')->sole();
        $this->assertSame($user->id, $log->causer_id);
    }

    public function test_follow_up_questions_send_only_the_recent_history(): void
    {
        $user = $this->user();
        $conversation = null;

        foreach (range(1, 5) as $i) {
            $this->fake->push('Antwort '.$i);
            $conversation = $this->assistant()->ask($user, $conversation, 'Frage '.$i);
        }

        $sent = $this->fake->calls[4]['messages'];
        $this->assertCount(HelpAssistant::HISTORY + 1, $sent);
        $this->assertSame(['user', 'Frage 2'], [$sent[0]['role'], $sent[0]['content']]);
        $this->assertSame('Frage 5', end($sent)['content']);
        $this->assertSame(10, $conversation->messages()->count(), 'Gespeichert bleibt alles.');
    }

    public function test_data_model_only_when_switched_on_and_only_viewable_tables(): void
    {
        $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string'],
            ['name' => 'stufe', 'label' => 'Stufe', 'type' => 'select', 'options' => ['options' => [['value' => 'Gold'], ['value' => 'Silber']]]],
        ]);
        $this->service()->createTable(['name' => 'gehaelter', 'label' => 'Gehälter'], [['name' => 'betrag', 'label' => 'Betrag', 'type' => 'integer']]);
        $user = $this->user(['help.assistant', 'data.kunden.view']);

        $this->fake->push('A')->push('B')->push('C');
        $this->assistant()->ask($user, null, 'Frage');
        $this->assertStringNotContainsString('Data model of this installation', $this->systemText(), 'Ohne Schalter kein Datenmodell.');

        $settings = app(AppSettings::class);
        $settings->ai_help_schema = true;
        $settings->save();
        app(HelpAssistant::class)->ask($user, null, 'Frage');
        $schema = $this->fake->calls[1]['system'][1]['text'];
        $this->assertStringContainsString('kunden', $schema);
        $this->assertStringContainsString('Gold', $schema);
        $this->assertStringNotContainsString('gehaelter', $schema, 'Nur Tabellen mit Leserecht.');

        $settings->ai_send_choices = false;
        $settings->save();
        app(HelpAssistant::class)->ask($user, null, 'Frage');
        $this->assertStringNotContainsString('Gold', $this->fake->calls[2]['system'][1]['text'], 'Auswahlwerte nur, wenn freigegeben.');
    }

    public function test_limits_errors_and_foreign_conversations(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->fake->push('A');
        $foreign = $this->assistant()->ask($other, null, 'Meine Frage');

        foreach ([
            fn () => $this->assistant()->ask($user, $foreign, 'Weiter'),
            fn () => $this->assistant()->ask($user, null, str_repeat('x', HelpAssistant::MAX_QUESTION + 1)),
            fn () => $this->assistant()->ask($this->user([]), null, 'Ohne Recht'),
        ] as $call) {
            try {
                $call();
                $this->fail('Angenommen.');
            } catch (BusinessRuleException) {
            }
        }

        $this->fake->fail();

        try {
            $this->assistant()->ask($user, null, 'Netz weg');
            $this->fail('Fehler verschluckt.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(__('assistant.errors.failed'), $e->getMessage());
        }

        $this->assertSame(0, AiHelpConversation::query()->ownedBy($user)->count(), 'Fehlgeschlagene Fragen legen nichts an.');

        $user->forceFill(['ai_daily_limit' => 0])->save();
        $this->expectException(BusinessRuleException::class);
        $this->assistant()->ask($user->fresh(), null, 'Gesperrt');
    }

    public function test_chat_component_asks_opens_and_deletes_own_conversations(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->fake->push('Wählen Sie **Neu**. Siehe [4.2](#user-42-datensätze-ansehen-anlegen-bearbeiten-löschen).');

        $chat = Livewire::test(HelpAssistantChat::class)
            ->assertSee(__('help.intro'))
            ->set('question', 'Wie lege ich einen Datensatz an?')
            ->call('ask')
            ->assertSet('question', '')
            ->assertSee('Wie lege ich einen Datensatz an?')
            ->assertSeeHtml('<strong>Neu</strong>');

        $id = $chat->get('conversationId');
        $this->assertNotNull($id);

        $chat->call('newConversation')->assertSet('conversationId', null)
            ->call('open', $id)->assertSet('conversationId', $id)
            ->set('question', '')->call('ask')->assertSet('error', __('assistant.errors.prompt_length', ['max' => HelpAssistant::MAX_QUESTION]));

        $foreign = $this->assistant()->ask($this->user(), null, 'Fremd');
        $chat->call('open', $foreign->id)->assertSet('conversationId', null)
            ->call('delete', $foreign->id);
        $this->assertNotNull($foreign->fresh(), 'Fremde Unterhaltung bleibt.');

        $chat->call('delete', $id)->assertSet('conversationId', null);
        $this->assertNull(AiHelpConversation::query()->find($id));
    }

    public function test_role_simulation_applies_to_the_assistant(): void
    {
        $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [['name' => 'name', 'label' => 'Name', 'type' => 'string']]);
        $settings = app(AppSettings::class);
        $settings->ai_help_schema = true;
        $settings->save();
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $simulation = app(RoleSimulation::class);

        // Rolle ohne Recht für den Assistenten: kein Knopf, keine Antwort – wie für eine Person mit dieser Rolle.
        $plain = Role::create(['name' => 'nur-lesen', 'guard_name' => 'web']);
        $simulation->start($admin, [$plain]);
        $simulation->apply($admin);
        $this->assertFalse(app(HelpAssistant::class)->availableFor($admin));

        // Rolle mit dem Recht, ohne Administration und ohne Datenrechte: nur Anwenderhandbuch und FAQ, kein Datenmodell.
        $helped = Role::create(['name' => 'mit-hilfe', 'guard_name' => 'web']);
        $helped->givePermissionTo('help.assistant');
        $simulation->stop($admin);
        $simulation->start($admin, [$helped]);
        $simulation->apply($admin);
        $this->fake->push('A');
        app(HelpAssistant::class)->ask($admin, null, 'Frage');

        $this->assertStringNotContainsString('<document id="admin"', $this->systemText());
        $this->assertStringNotContainsString('Data model of this installation', $this->systemText(), 'Ohne Leserecht auf Tabellen kein Datenmodell.');
        $this->assertStringContainsString('Roles: mit-hilfe', $this->systemText(), 'Rechte der simulierten Rolle.');
        $this->assertStringContainsString('✗ Benutzer: anlegen', $this->systemText());
        $this->assertStringNotContainsString('→ /admin/users', $this->systemText(), 'Menü der simulierten Rolle (ohne Benutzerverwaltung).');
        $this->assertSame(1, AiHelpConversation::query()->ownedBy($admin)->count(), 'Die Unterhaltung gehört der echten Person.');
    }

    public function test_own_roles_and_permissions_go_along_uncached(): void
    {
        $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [['name' => 'name', 'label' => 'Name', 'type' => 'string']]);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['help.assistant', 'users.view', 'data.kunden.view']);
        $user = $this->user([]);
        $user->assignRole($role);
        $this->fake->push('A')->push('B');

        $this->assistant()->ask($user->fresh(), null, 'Warum kann ich keine neuen Benutzer anlegen?');

        $find = static fn (array $system): array => collect($system)->first(static fn (array $b): bool => str_starts_with($b['text'], 'Roles and permissions'));
        $block = $find($this->fake->calls[0]['system']);
        $this->assertFalse($block['cache'] ?? false, 'Je Person anders: nicht zwischengespeichert.');
        $this->assertStringContainsString('Roles: admin', $block['text']);
        $this->assertStringContainsString('✓ Benutzer: anzeigen', $block['text']);
        $this->assertStringContainsString('✗ Benutzer: anlegen', $block['text']);
        $this->assertStringContainsString('Daten: Kunden: anzeigen', $block['text']);
        $this->assertStringNotContainsString('Daten: Kunden: löschen', $block['text'], 'Nur vorhandene Datenrechte.');

        $this->assistant()->ask($this->superAdmin(), null, 'Frage');
        $this->assertStringContainsString('The role super-admin has every permission.', $find($this->fake->calls[1]['system'])['text']);
    }

    public function test_answers_link_pages_from_the_persons_menu_only(): void
    {
        $user = $this->user(['help.assistant', 'users.view']);
        $this->actingAs($user);
        $this->fake->push('Unter [Administration › Benutzer und Zugriff › Benutzer](/admin/users) auf **Neu** klicken; nicht [Rollen](/admin/roles), nicht [hier](https://example.com) und nicht [erfunden](/admin/gibt-es-nicht).');

        $conversation = $this->assistant()->ask($user, null, 'Wo lege ich Benutzer an?');

        $menu = end($this->fake->calls[0]['system'])['text'];
        $this->assertStringStartsWith('Menu of the person asking', $menu);
        $this->assertStringContainsString('Administration › Benutzer und Zugriff › Benutzer → /admin/users', $menu, 'Voller Pfad bis zur Seite im Untermenü.');
        $this->assertStringNotContainsString('/admin/roles', $menu, 'Nur Einträge, die die Person sieht.');

        $answer = $conversation->messages()->get()->last()->content;
        $this->assertStringContainsString('[Administration › Benutzer und Zugriff › Benutzer](/admin/users)', $answer);
        $this->assertStringContainsString('nicht Rollen, nicht hier und nicht erfunden.', $answer, 'Nicht sichtbare, externe und erfundene Adressen werden Text.');

        $html = $this->assistant()->html($user, $answer);
        $this->assertStringContainsString('<a href="/admin/users" data-help-page>', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
    }

    public function test_question_mark_button_and_documentation_tab(): void
    {
        $this->actingAs($this->user());
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('data-help-button', false);
        $this->get(DocumentationPage::getUrl())->assertOk()->assertSee('data-doc-tab="assistant"', false);

        $this->actingAs($this->user([]));
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('data-help-button', false);
        $this->get(DocumentationPage::getUrl())->assertOk()->assertDontSee('data-doc-tab="assistant"', false);
    }
}
