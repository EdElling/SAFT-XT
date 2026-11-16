<?php

declare(strict_types=1);

namespace Tests\Feature\ErrorHandling;

use App\Exceptions\BusinessRuleException;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Feature\ErrorHandling\Fixtures\FailingComponent;
use Tests\TestCase;

class LivewireErrorHandlingTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        $this->actingAs($this->superAdmin());
    }

    public function test_foreign_key_violation_becomes_notification_and_rolls_back(): void
    {
        $before = Menu::count();

        Livewire::test(FailingComponent::class)
            ->call('foreignKeyViolation')
            ->assertOk();

        Notification::assertNotified(__('errors.titles.conflict'));
        $this->assertSame($before, Menu::count());
    }

    public function test_unique_violation_names_the_translated_field(): void
    {
        $existing = User::factory()->create();

        Livewire::test(FailingComponent::class)
            ->call('uniqueViolation', $existing->email)
            ->assertOk();

        $notification = $this->lastNotification();
        $this->assertStringContainsString('„E-Mail“', (string) $notification->getBody());
    }

    public function test_business_rule_violation_becomes_notification(): void
    {
        Livewire::test(FailingComponent::class)->call('businessRule')->assertOk();

        Notification::assertNotified(__('errors.titles.business_rule'));
    }

    public function test_unexpected_errors_are_not_swallowed(): void
    {
        $this->expectException(RuntimeException::class);

        Livewire::test(FailingComponent::class)->call('unexpected');
    }

    public function test_errors_during_initial_render_are_not_swallowed(): void
    {
        // Beim initialen Rendern darf der Hook nicht greifen: 404-Seite statt Komponente.
        Livewire::test(FailingComponent::class, ['failOnMount' => true])
            ->assertStatus(404)
            ->assertDontSee('failing');
    }

    public function test_super_admin_role_is_protected_on_model_level(): void
    {
        $role = Role::findByName(User::ROLE_SUPER_ADMIN);

        try {
            $role->delete();
            $this->fail('BusinessRuleException erwartet.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('role.protected', $e->errorCode);
        }

        $this->assertModelExists($role);
    }

    private function lastNotification(): Notification
    {
        // Gleiches Vorgehen wie Notification::assertNotified().
        app(NotificationMailbox::class)->flush();
        $component = new Notifications;
        $component->mount();
        $component->pullNotifications();

        $notification = $component->notifications->last();
        $this->assertInstanceOf(Notification::class, $notification, 'Keine Notification gesendet.');

        return $notification;
    }
}
