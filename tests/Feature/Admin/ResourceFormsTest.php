<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class ResourceFormsTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);
    }

    public function test_editing_a_user_is_audited_with_causer_and_role_changes(): void
    {
        $user = $this->userWithPermissions();
        $editor = Role::create(['name' => 'editor']);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['name' => 'Neuer Name', 'roles' => [$editor->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $logs = Activity::query()->where('subject_type', User::class)->where('subject_id', $user->id)
            ->where('event', 'updated')->get();

        $this->assertTrue($logs->every(fn (Activity $a): bool => $a->causer_id === $this->admin->id));
        $this->assertTrue($logs->contains(fn (Activity $a): bool => ($a->attribute_changes['attributes']['name'] ?? null) === 'Neuer Name'));
        $this->assertTrue($logs->contains(fn (Activity $a): bool => ($a->attribute_changes['attributes']['roles'] ?? null) === ['editor']));
    }

    /** Der Browser darf das gespeicherte Anmeldepasswort nicht eintragen; leer bleibt das Passwort unverändert. */
    public function test_password_fields_are_not_autofilled_and_may_stay_empty(): void
    {
        $user = $this->userWithPermissions();
        $hash = $user->password;

        $page = Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])->assertFormSet(['password' => null]);
        $this->assertSame(2, substr_count($page->html(), 'autocomplete="new-password"'));

        $page->fillForm(['name' => 'Ohne Passwort'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($hash, $user->fresh()->password);
    }

    /** „Endgültig löschen“ steht in der Liste als Zeilenaktion bereit, aber nur bei gelöschten Konten. */
    public function test_user_list_offers_force_delete_only_for_deleted_users(): void
    {
        $active = $this->userWithPermissions();
        $deleted = $this->userWithPermissions();
        $deleted->delete();

        // Filter „mit gelöschten“: beide Konten stehen in der Liste.
        Livewire::test(ListUsers::class)
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$active, $deleted])
            ->assertActionHidden(TestAction::make('forceDelete')->table($active))
            ->assertActionVisible(TestAction::make('forceDelete')->table($deleted))
            ->mountAction(TestAction::make('forceDelete')->table($deleted))
            ->assertMountedActionModalSee(__('users.delete.force'))
            ->callMountedAction();

        $this->assertNull(User::withTrashed()->find($deleted->id));
        $this->assertNotNull($active->fresh());
    }

    public function test_creating_a_user_hashes_password_and_requires_confirmation(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Neu', 'email' => 'neu@example.com', 'password' => 'Geheim-12345', 'password_confirmation' => 'falsch'])
            ->call('create')
            ->assertHasFormErrors(['password']);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Neu', 'email' => 'neu@example.com', 'password' => 'Geheim-12345', 'password_confirmation' => 'Geheim-12345'])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'neu@example.com')->firstOrFail();
        $this->assertNotSame('Geheim-12345', $user->password);
        $this->assertTrue(password_verify('Geheim-12345', (string) $user->password));
    }

    public function test_non_super_admin_cannot_assign_super_admin_role(): void
    {
        $manager = $this->userWithPermissions(['users.view', 'users.update']);
        $target = $this->userWithPermissions();
        $this->actingAs($manager);

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['roles' => [Role::findByName(User::ROLE_SUPER_ADMIN)->id]])
            ->call('save')
            ->assertHasFormErrors(['roles']);

        $this->assertFalse($target->fresh()->isSuperAdmin());
    }

    /** Rechte erscheinen alphabetisch nach ihrer Bezeichnung in der aktuellen Sprache, ohne Datenrechte. */
    public function test_role_permissions_are_sorted_by_displayed_label(): void
    {
        $role = Role::create(['name' => 'sortierung']);

        foreach (['de', 'en'] as $locale) {
            app()->setLocale($locale);

            Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
                ->assertFormFieldExists('rights.permissions', function ($field) use ($locale): bool {
                    $labels = array_values($field->getOptions());
                    $sorted = $labels;
                    (new \Collator($locale))->sort($sorted);

                    return count($labels) > 10 && $labels === $sorted
                        && ! collect($labels)->contains(fn (string $label): bool => str_starts_with($label, __('roles.data_resource', ['table' => ''])));
                });
        }
    }

    public function test_role_permission_changes_are_audited_and_effective_immediately(): void
    {
        $role = Role::create(['name' => 'viewer']);
        $user = $this->userWithPermissions()->assignRole($role);
        $this->assertFalse($user->fresh()->can('users.view'));

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['permissions' => [Permission::findByName('users.view')->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($user->fresh()->can('users.view'));
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Role::class,
            'subject_id' => $role->id,
            'event' => 'updated',
            'causer_id' => $this->admin->id,
        ]);
    }

    public function test_menu_cannot_become_its_own_descendant(): void
    {
        $administration = Menu::where('label->de', 'Administration')->firstOrFail();
        $users = Menu::where('label->de', 'Benutzer')->firstOrFail();

        Livewire::test(EditMenu::class, ['record' => $administration->getRouteKey()])
            ->fillForm(['parent_id' => $users->id])
            ->call('save')
            ->assertHasFormErrors(['parent_id']);
    }

    public function test_menu_depth_is_limited_to_three_levels(): void
    {
        $users = Menu::where('label->de', 'Benutzer')->firstOrFail();
        $level3 = Menu::create(['parent_id' => $users->id, 'label' => 'Ebene 3', 'route' => 'filament.admin.resources.users.index']);

        Livewire::test(CreateMenu::class)
            ->fillForm(['label.de' => 'Ebene 4', 'parent_id' => $level3->id, 'type' => 'url', 'url' => 'https://example.com'])
            ->call('create')
            ->assertHasFormErrors(['parent_id']);
    }

    public function test_switching_menu_type_clears_stale_target(): void
    {
        $users = Menu::where('label->de', 'Benutzer')->firstOrFail();

        Livewire::test(EditMenu::class, ['record' => $users->getRouteKey()])
            ->fillForm(['type' => 'url', 'url' => 'https://example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $users->refresh();
        $this->assertNull($users->route);
        $this->assertSame('https://example.com', $users->url);
    }

    public function test_activity_detail_shows_before_and_after_values(): void
    {
        $user = $this->userWithPermissions();
        $user->update(['name' => 'Vorher-Wert']);
        $user->update(['name' => 'Nachher-Wert']);

        $activity = Activity::query()->where('subject_id', $user->id)->where('subject_type', User::class)
            ->where('event', 'updated')->latest('id')->firstOrFail();

        $this->get("/admin/activity-log/{$activity->id}")
            ->assertOk()
            ->assertSeeInOrder(['Vorher-Wert', 'Nachher-Wert'])
            ->assertSee($this->admin->name)
            // Änderung: Tabelle Feld | Alt | Neu, keine Blöcke.
            ->assertDontSee('data-field-blocks', false)
            ->assertSee('overflow-wrap: anywhere', false);

        // Anlegen: nur neue Werte – als Blöcke „Feld | Wert“.
        $created = Activity::query()->where('subject_id', $user->id)->where('subject_type', User::class)
            ->where('event', 'created')->firstOrFail();
        $html = $this->get("/admin/activity-log/{$created->id}")->assertOk()->assertSee('data-field-blocks', false)->getContent();
        $this->assertMatchesRegularExpression('#<dt>email</dt>\s*<dd[^>]*>\s*'.preg_quote($user->email, '#').'\s*</dd>#', $html);
    }
}
