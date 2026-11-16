<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduledTasks\Schemas;

use App\Models\ScheduledTask;
use App\Tasks\Frequency;
use App\Tasks\TaskRegistry;
use App\Tasks\TaskSchedule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** Formular: Art und Parameter, Zeitplan, aktiv. Die Art lässt sich nach dem Anlegen nicht mehr ändern. */
class ScheduledTaskForm
{
    private const string TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public static function configure(Schema $schema): Schema
    {
        $registry = app(TaskRegistry::class);
        $is = fn (Frequency ...$frequencies): \Closure => fn (Get $get): bool => in_array($get('frequency'), array_map(fn (Frequency $f): string => $f->value, $frequencies), true);

        return $schema->columns(1)->components([
            Section::make(__('tasks.sections.task'))->columns(2)->schema([
                Select::make('type')
                    ->label(__('tasks.fields.type'))
                    ->options(fn (?ScheduledTask $record): array => $record !== null
                        ? [$record->type => $record->typeLabel()]
                        : $registry->creatableOptions())
                    ->required()
                    ->live()
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                TextInput::make('name')
                    ->label(__('tasks.fields.name'))
                    ->placeholder(fn (Get $get): string => is_string($get('type')) && $registry->has($get('type')) ? $registry->get($get('type'))->label() : '')
                    ->maxLength(120),
                // Felder der gewählten Art; $get liest hier im umgebenden Formular (die Gruppe setzt nur ihren statePath).
                Group::make(function (Get $get, ?ScheduledTask $record) use ($registry): array {
                    $type = $record?->type ?? $get('type');

                    return is_string($type) && $registry->has($type) ? $registry->get($type)->parameterFields() : [];
                })
                    ->statePath('parameters')
                    ->key('parameters')
                    ->columnSpanFull(),
            ]),
            Section::make(__('tasks.sections.schedule'))->columns(3)->schema([
                Select::make('frequency')
                    ->label(__('tasks.fields.frequency'))
                    ->options(Frequency::options())
                    ->default(Frequency::Daily->value)
                    ->required()
                    ->live(),
                Select::make('interval_hours')
                    ->label(__('tasks.fields.interval_hours'))
                    ->options(array_combine(Frequency::INTERVALS, array_map(fn (int $h): string => __('tasks.every_n_hours', ['hours' => $h]), Frequency::INTERVALS)))
                    ->visible($is(Frequency::EveryHours))
                    ->required($is(Frequency::EveryHours)),
                TextInput::make('minute')
                    ->label(__('tasks.fields.minute'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(59)
                    ->default(0)
                    ->visible($is(Frequency::Hourly))
                    ->required($is(Frequency::Hourly)),
                TextInput::make('at_time')
                    ->label(fn (Get $get): string => $get('frequency') === Frequency::EveryHours->value ? __('tasks.fields.from_time') : __('tasks.fields.at_time'))
                    ->type('time')
                    ->regex(self::TIME)
                    ->default('06:00')
                    ->visible($is(Frequency::EveryHours, Frequency::Daily, Frequency::Weekdays, Frequency::Weekly))
                    ->required($is(Frequency::EveryHours, Frequency::Daily, Frequency::Weekdays, Frequency::Weekly)),
                Select::make('weekday')
                    ->label(__('tasks.fields.weekday'))
                    ->options(fn (): array => TaskSchedule::weekdayOptions())
                    ->default(1)
                    ->visible($is(Frequency::Weekly))
                    ->required($is(Frequency::Weekly)),
                Toggle::make('is_active')
                    ->label(__('tasks.fields.active'))
                    ->default(true)
                    ->columnSpanFull(),
                Text::make(fn (?Model $record): string => $record instanceof ScheduledTask
                    ? __('tasks.next_run_text', ['time' => $record->next_run_at?->format('d.m.Y H:i') ?? '–'])
                    : '')
                    ->visibleOn('edit')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    /**
     * Gespeicherte Werte → Formular (Minute für „stündlich“).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toForm(array $data): array
    {
        $data['minute'] = (int) substr((string) ($data['at_time'] ?? '00:00'), 3, 2);
        $data['parameters'] = (array) ($data['parameters'] ?? []);

        return $data;
    }

    /**
     * Formular → gespeicherte Werte: nur die Angaben, die zum Intervall gehören.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toRecord(array $data): array
    {
        $frequency = Frequency::from((string) $data['frequency']);

        if ($frequency === Frequency::Hourly) {
            $data['at_time'] = sprintf('00:%02d', (int) ($data['minute'] ?? 0));
        }

        $data['interval_hours'] = $frequency === Frequency::EveryHours ? (int) $data['interval_hours'] : null;
        $data['weekday'] = $frequency === Frequency::Weekly ? (int) $data['weekday'] : null;
        $data['name'] = filled($data['name'] ?? null) ? trim((string) $data['name']) : null;
        unset($data['minute']);

        return $data;
    }
}
