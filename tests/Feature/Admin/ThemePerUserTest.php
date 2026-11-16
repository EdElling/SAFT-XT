<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Hell/Dunkel/System gilt je Benutzer, nicht je Browser. */
class ThemePerUserTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
    }

    public function test_choice_is_saved_per_user_and_applied_before_filament_reads_it(): void
    {
        $alice = $this->userWithPermissions();
        $bob = $this->userWithPermissions();

        $this->postJson(route('my-theme'), ['theme' => 'dark'])->assertUnauthorized();

        $this->actingAs($alice)->postJson(route('my-theme'), ['theme' => 'dark'])->assertOk();
        $this->actingAs($alice)->postJson(route('my-theme'), ['theme' => 'pink'])->assertUnprocessable();
        $this->assertSame('dark', $alice->fresh()->theme);

        // Jede Seite setzt die Wahl des angemeldeten Benutzers; ohne eigene Wahl „System“.
        $this->actingAs($alice->fresh())->get('/admin')->assertOk()
            ->assertSee("var current='dark';localStorage.setItem('filament:theme:mode',current);", false)
            ->assertSee("addEventListener('theme-changed'", false)
            // Gespeichert wird nur eine echte Änderung, nicht das Melden beim Laden der Seite.
            ->assertSee('if(e.detail===current){return;}', false);
        $this->actingAs($bob)->get('/admin')->assertOk()
            ->assertSee("var current='system';", false);
    }
}
