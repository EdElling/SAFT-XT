<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Auth\AuthMode;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Services\DynamicSchemaService;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Änderungsprotokoll (Bereich PRT). */
class ActivityLogTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->superAdmin();
    }

    private function table(string $name = 'kunden', string $label = 'Kunden'): CustomTable
    {
        return app(DynamicSchemaService::class)->createTable(['name' => $name, 'label' => $label], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string'],
        ]);
    }

    private function entry(User $causer, string $event, ?object $subject, string $date = 'now'): Activity
    {
        $logger = activity()->causedBy($causer)->event($event);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        $activity = $logger->log($event);
        $activity->forceFill(['created_at' => $date])->save();

        return $activity;
    }

    private function rows(Browser $browser): int
    {
        return count($browser->elements('tr.fi-ta-row'));
    }

    /** PRT-01 */
    public function test_log_filters_by_user_event_type_and_period(): void
    {
        $other = $this->activeUser('anna@example.com');
        $record = DynamicRecord::forTable($this->table())->create(['firma' => 'ACME']);
        Activity::query()->delete();
        $this->entry($this->admin, 'updated', $record, '2026-01-15 10:00:00');
        $this->entry($other, 'created', $record, '2026-03-15 10:00:00');
        $this->entry($other, 'deleted', $this->admin, '2026-03-20 10:00:00');
        $otherName = $other->name;

        $this->browse(function (Browser $browser) use ($otherName): void {
            $this->login($browser, self::ADMIN);
            $base = Activity::query()->count();
            $browser->visit('/admin/activity-log')->waitFor('.fi-ta-table', 15);
            $this->assertSame($base, $this->rows($browser));

            $this->applyFilters($browser, function (Browser $b) use ($otherName): void {
                $this->pickOption($b, 'tableFiltersForm.causer_id.value', $otherName);
            });
            $browser->waitUsing(15, 100, fn (): bool => $this->rows($browser) === 2);
            $this->resetFilters($browser);

            $this->applyFilters($browser, static function (Browser $b): void {
                $b->select('select[id="tableFiltersForm.event.value"]', 'updated');
            });
            $browser->waitUsing(15, 100, fn (): bool => $this->rows($browser) === 1);
            $this->resetFilters($browser);

            $this->applyFilters($browser, static function (Browser $b): void {
                $b->assertSeeIn('.fi-ta-filters', 'Objekttyp');
                $b->select('select[id="tableFiltersForm.subject_type.value"]', 'dynamic:kunden');
            });
            $browser->waitUsing(15, 100, fn (): bool => $this->rows($browser) === 2)->assertSee('Daten: Kunden');
            $this->resetFilters($browser);

            $this->applyFilters($browser, static function (Browser $b): void {
                $b->keys('input[id="tableFiltersForm.created_at.from"]', '10012026')
                    ->keys('input[id="tableFiltersForm.created_at.until"]', '20012026');
            });
            $browser->waitUsing(15, 100, fn (): bool => $this->rows($browser) === 1)->assertSee('15.01.2026');
        });
    }

    /** PRT-02 */
    public function test_detail_view_shows_readable_before_and_after(): void
    {
        $user = $this->activeUser('anna@example.com');
        $user->update(['name' => 'Jürgen Müller']);
        $activity = Activity::query()->where('subject_id', $user->id)->where('event', 'updated')->latest('id')->firstOrFail();

        $this->browse(function (Browser $browser) use ($activity): void {
            $this->login($browser, self::ADMIN)->visit("/admin/activity-log/{$activity->id}")
                ->waitForText('Änderungen', 15)
                ->assertSee('Vorher')
                ->assertSee('Nachher')
                ->assertSee('Jürgen Müller')
                ->assertSee('name');
        });
    }

    /** PRT-03 */
    public function test_log_cannot_be_changed_even_by_super_admin(): void
    {
        $activity = $this->entry($this->admin, 'updated', $this->admin);

        $this->browse(function (Browser $browser) use ($activity): void {
            $this->login($browser, self::ADMIN)->visit('/admin/activity-log')->waitFor('.fi-ta-table', 15)
                ->assertDontSee('Bearbeiten')
                ->assertDontSee('Löschen')
                ->assertDontSee('Erstellen')
                ->assertMissing('input.fi-ta-record-checkbox');

            $browser->visit("/admin/activity-log/{$activity->id}")->waitForText('Beschreibung', 15)
                ->assertDontSee('Bearbeiten')
                ->assertDontSee('Löschen');

            $browser->visit("/admin/activity-log/{$activity->id}/edit")->assertSee('Nicht gefunden');

            $browser->visit('/admin/activity-log/create')->assertSee('Nicht gefunden');
        });
    }

    /** PRT-04 */
    public function test_logins_failures_and_logouts_are_logged_without_passwords(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/admin/login');
            $this->submitLogin($browser, self::ADMIN, 'Falsches-Geheimnis-1')->waitForText(self::TEXT_LOGIN_FAILED, 15);
            $this->login($browser, self::ADMIN)->visit('/admin/users')->waitFor('.fi-ta-table', 15);
            $this->logout($browser);
            $this->login($browser, self::ADMIN)->visit('/admin/activity-log')->waitFor('.fi-ta-table', 15)
                ->assertSee('Anmeldung fehlgeschlagen')
                ->assertSee('Anmeldung')
                ->assertSee('Abmeldung');

            $this->assertStringNotContainsString('Falsches-Geheimnis-1', $browser->driver->getPageSource());

            // Der Filter bietet auch die Anmeldeereignisse an, auf die das Dashboard verweist.
            $this->applyFilters($browser, static function (Browser $b): void {
                $b->select('select[id="tableFiltersForm.event.value"]', 'login_failed');
            });
            $browser->waitUsing(15, 100, fn (): bool => $this->rows($browser) === 1)->assertSee('Anmeldung fehlgeschlagen');
        });

        $this->assertSame(0, Activity::query()->where('properties', 'like', '%Falsches-Geheimnis-1%')->count());
    }

    /** PRT-05 */
    public function test_settings_changes_are_logged_with_before_and_after(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/app-settings')->waitFor('input[id="form.app_name"]', 15)
                ->clear('input[id="form.app_name"]')->type('input[id="form.app_name"]', 'Neuer Name')
                ->press('Änderungen speichern')->pause(1500);

            $browser->visit('/admin/auth-settings')->waitForText('Anmeldemodus', 15)
                ->clickAtXPath("//label[contains(., '".AuthMode::Hybrid->label()."')]")
                ->pause(500)->press('Änderungen speichern')->pause(1500);

            $browser->visit('/admin/activity-log')->waitFor('.fi-ta-table', 15)
                ->assertSee('Einstellungen: app')
                ->assertSee('Einstellungen: auth');

            $this->rowAction($browser, 'Einstellungen: app', 'Anzeigen')->waitForText('Neuer Name', 15)->assertSee('SAFT');
        });
    }

    /** PRT-06 */
    public function test_entries_of_a_deleted_table_stay_readable(): void
    {
        $table = $this->table('alt', 'Altdaten');
        $record = DynamicRecord::forTable($table)->create(['firma' => 'Vergangen']);
        $activity = Activity::query()->where('subject_type', $record->getMorphClass())->where('subject_id', $record->id)->firstOrFail();
        $service = app(DynamicSchemaService::class);
        $service->updateTable($table, ['is_active' => false]);
        $service->dropTable($table->fresh(), 'alt');

        $this->browse(function (Browser $browser) use ($activity): void {
            $this->login($browser, self::ADMIN)->visit('/admin/activity-log')->waitFor('.fi-ta-table', 15)
                ->assertDontSee('Unerwarteter Fehler')
                ->assertDontSee('Datenbankfehler');

            $browser->visit("/admin/activity-log/{$activity->id}")->waitForText('Beschreibung', 15)
                ->assertSee('Vergangen')
                ->assertDontSee('Unerwarteter Fehler');
        });
    }

    /** PRT-07 */
    public function test_newest_entries_come_first_and_paging_works(): void
    {
        Activity::query()->delete();

        for ($number = 1; $number <= 15; $number++) {
            $this->entry($this->admin, 'updated', $this->admin, sprintf('2026-02-%02d 10:00:00', $number));
        }

        $this->browse(function (Browser $browser): void {
            $browser->visit('/admin/login');
            $this->login($browser, self::ADMIN)->visit('/admin/activity-log')->waitFor('.fi-ta-table', 15);

            $total = Activity::query()->count();
            $browser->assertSeeIn('.fi-pagination-overview', "von {$total}");
            $this->assertStringStartsWith(now()->format('d.m.Y'), $this->firstColumn($browser)[0]);

            $this->clickVisible($browser, '[rel="next"]')->waitForTextIn('.fi-pagination-overview', '11 bis', 15);
            $this->assertNotEmpty($this->firstColumn($browser));

            // Umgekehrte Sortierung zeigt den ältesten Eintrag zuerst.
            $browser->click('th.fi-ta-header-cell-created-at button')
                ->waitUsing(15, 100, fn (): bool => str_starts_with($this->firstColumn($browser)[0] ?? '', '01.02.2026'));
        });
    }
}
