<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\RoleSimulation;
use App\Filament\Concerns\LimitsFormWidth;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * „Ansicht als Rolle“ starten (nur Super-Admins, Benutzermenü). Danach gelten bis zum Beenden
 * ausschließlich die Rechte der gewählten Rolle (siehe RoleSimulation).
 */
class RoleSimulationPage extends Page
{
    use LimitsFormWidth;

    protected static ?string $slug = 'role-simulation';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ! app(RoleSimulation::class)->isActive() && app(RoleSimulation::class)->canSimulate($user);
    }

    public function getTitle(): string
    {
        return __('role_simulation.title');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description(__('role_simulation.help'))
                    ->schema([
                        // Mehrere Rollen: so, wie eine Person sie tatsächlich hat (z. B. „benutzer“ + „forschung“).
                        CheckboxList::make('roles')
                            ->label(__('role_simulation.roles'))
                            ->helperText(__('role_simulation.roles_help'))
                            ->options(fn (): array => Role::query()->where('name', '!=', User::ROLE_SUPER_ADMIN)->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (): array => Role::query()->where('name', User::ROLE_DEFAULT)->pluck('id')->all())
                            ->columns(3)
                            ->required(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('start')
                ->footer([Actions::make([Action::make('start')->label(__('role_simulation.start'))->submit('start')])]),
        ]);
    }

    public function start(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $roles = Role::query()->whereKey(array_map('intval', (array) $this->form->getState()['roles']))->get();

        app(RoleSimulation::class)->start($user, $roles);

        $this->redirect(Dashboard::getUrl(), navigate: false);
    }
}
