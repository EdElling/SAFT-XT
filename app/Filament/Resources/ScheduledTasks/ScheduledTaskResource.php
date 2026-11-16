<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduledTasks;

use App\Filament\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\ScheduledTasks\Pages\CreateScheduledTask;
use App\Filament\Resources\ScheduledTasks\Pages\EditScheduledTask;
use App\Filament\Resources\ScheduledTasks\Pages\ListScheduledTasks;
use App\Filament\Resources\ScheduledTasks\RelationManagers\TaskRunsRelationManager;
use App\Filament\Resources\ScheduledTasks\Schemas\ScheduledTaskForm;
use App\Filament\Resources\ScheduledTasks\Tables\ScheduledTasksTable;
use App\Filament\Support\LocalizedTitleCase;
use App\Models\ScheduledTask;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Administration › Geplante Aufgaben. Rechte tasks.*; System-Aufgaben lassen sich nicht löschen. */
class ScheduledTaskResource extends Resource
{
    use LocalizedTitleCase;

    protected static ?string $model = ScheduledTask::class;

    protected static ?string $slug = 'scheduled-tasks';

    protected static bool $shouldRegisterNavigation = false;

    /** Filament-Aktion => Recht tasks.<recht> */
    private const array ABILITIES = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'create',
        'update' => 'update',
        'delete' => 'delete',
    ];

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? $action->name : $action;
        $ability = self::ABILITIES[$action] ?? null;
        $user = auth()->user();

        if ($action === 'delete' && $record instanceof ScheduledTask && $record->is_system) {
            return Response::deny();
        }

        return $ability !== null && $user instanceof User && $user->can("tasks.{$ability}")
            ? Response::allow()
            : Response::deny();
    }

    public static function getModelLabel(): string
    {
        return __('tasks.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tasks.plural');
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof ScheduledTask ? $record->displayName() : '';
    }

    public static function form(Schema $schema): Schema
    {
        return ScheduledTaskForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScheduledTasksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [TaskRunsRelationManager::class, ActivitiesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduledTasks::route('/'),
            'create' => CreateScheduledTask::route('/create'),
            'edit' => EditScheduledTask::route('/{record}/edit'),
        ];
    }
}
