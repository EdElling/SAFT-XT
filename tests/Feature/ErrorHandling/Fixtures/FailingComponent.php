<?php

declare(strict_types=1);

namespace Tests\Feature\ErrorHandling\Fixtures;

use App\Exceptions\BusinessRuleException;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use RuntimeException;

/**
 * Test-Komponente, die gezielt Fehler in Livewire-Aktionen auslöst.
 */
class FailingComponent extends Component
{
    public bool $failOnMount = false;

    public function mount(bool $failOnMount = false): void
    {
        if ($failOnMount) {
            User::query()->findOrFail(PHP_INT_MAX);
        }
    }

    public function foreignKeyViolation(): void
    {
        // Wie Filament: innerhalb einer Transaktion -> Rollback inkl. vorheriger Schreibvorgänge.
        DB::transaction(function (): void {
            Menu::create(['label' => 'Wird zurückgerollt']);
            Menu::create(['label' => 'Ungültig', 'parent_id' => 999_999]);
        });
    }

    public function uniqueViolation(string $email): void
    {
        DB::transaction(fn () => User::factory()->create(['email' => $email]));
    }

    public function businessRule(): void
    {
        throw new BusinessRuleException('Regel verletzt.');
    }

    public function unexpected(): void
    {
        throw new RuntimeException('Interner Fehler mit Geheimnis');
    }

    public function render(): string
    {
        return '<div>failing</div>';
    }
}
