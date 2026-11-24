<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Gleichzeitiges Bearbeiten in der Verwaltung (Fundament 2, F2): Edit-Seiten prüfen updated_at vor dem Speichern. */
class ConcurrentMetadataEditTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        $this->actingAs($this->superAdmin());
    }

    public function test_stale_edit_page_warns_and_allows_reload_or_overwrite(): void
    {
        $role = Role::findOrCreate('lektorat', 'web');
        $page = Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])->fillForm(['name' => 'lektorat-neu']);
        $this->assertNotNull($page->get('loadedVersion'));

        // Inzwischen ändert jemand anderes (Zeitstempel bewegt sich).
        Role::query()->whereKey($role->getKey())->update(['name' => 'korrektorat', 'updated_at' => now()->addMinutes(5)]);

        $page->call('save')
            ->assertNotified(__('errors.titles.stale_record'))
            ->assertHasNoFormErrors();
        $this->assertSame('korrektorat', $role->fresh()->name, 'Nichts überschrieben.');

        // „Neu laden“ holt den aktuellen Stand ins Formular.
        $page->dispatch('stale-record-reload', component: $page->instance()->getId())
            ->assertFormSet(['name' => 'korrektorat']);

        // „Trotzdem speichern“ mit der aktuellen Version.
        $page->fillForm(['name' => 'lektorat-neu'])
            ->dispatch('stale-record-overwrite', component: $page->instance()->getId(), version: $role->fresh()->updated_at->format('Y-m-d H:i:s'))
            ->assertHasNoFormErrors();
        $this->assertSame('lektorat-neu', $role->fresh()->name);

        // Ohne fremde Änderung speichert die Seite wie bisher.
        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'lektorat'])
            ->call('save')
            ->assertNotNotified(__('errors.titles.stale_record'));
        $this->assertSame('lektorat', $role->fresh()->name);
    }

    /** Zweimal speichern ohne Neuladen: die eigene Änderung ist keine fremde (Browser-Suite 24.11.2026, MEN-04 und BEN-02). */
    public function test_saving_twice_without_reloading_is_not_a_concurrent_edit(): void
    {
        $role = Role::findOrCreate('lektorat', 'web');
        Role::query()->whereKey($role->getKey())->update(['updated_at' => now()->subMinute()]);

        $page = Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'lektorat-neu'])->call('save')
            ->assertNotNotified(__('errors.titles.stale_record'));
        $this->assertSame('lektorat-neu', $role->fresh()->name);

        $page->fillForm(['name' => 'lektorat-neuer'])->call('save')
            ->assertNotNotified(__('errors.titles.stale_record'))
            ->assertHasNoFormErrors();
        $this->assertSame('lektorat-neuer', $role->fresh()->name);
    }
}
