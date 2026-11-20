<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\RowIcons;
use App\Logs\LogEntry;
use App\Logs\LogReader;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Administration › Systemprotokoll (B-065): das Ende der Logdatei in storage/logs, neueste Einträge zuerst, mit Filter
 * nach Stufe und Suche (z. B. nach der Referenz eines fehlgeschlagenen Laufs). Nur lesen; Recht system_log.view; jeder
 * Aufruf steht im Änderungsprotokoll. Die Einträge können Pfade, SQL mit Werten, E-Mail- und IP-Adressen enthalten.
 */
class SystemLogPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'system-log';

    protected static bool $shouldRegisterNavigation = false;

    /** Gewählte Datei (nur Namen aus storage/logs, siehe LogReader::path). */
    #[Url]
    public ?string $file = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('system_log.view') ?? false;
    }

    public function getTitle(): string
    {
        return __('system_log.title');
    }

    public function mount(): void
    {
        // Suche aus der Adresse übernehmen (Link aus den Fehlerdetails eines Laufs: ?search=REFERENZ).
        if (is_string($search = request()->query('search')) && $search !== '') {
            $this->tableSearch = $search;
        }

        $user = auth()->user();
        activity('system')
            ->causedBy($user instanceof User ? $user : null)
            ->event('system_log_viewed')
            ->withProperties(['file' => basename((string) $this->reader()->path($this->file))])
            ->log('system_log_viewed');
    }

    private function reader(): LogReader
    {
        return app(LogReader::class);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(fn (): string => __('system_log.intro', ['file' => basename((string) $this->reader()->path($this->file)), 'max' => LogReader::MAX_ENTRIES])),
            Text::make(__('system_log.truncated', ['mb' => LogReader::MAX_BYTES / 1024 / 1024]))
                ->color('warning')
                ->visible(fn (): bool => $this->reader()->truncated($this->file)),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, ?array $filters, int|string $page, int|string $recordsPerPage): LengthAwarePaginator {
                $level = $filters['level']['value'] ?? null;
                $entries = array_values(array_filter(
                    $this->reader()->entries($this->file),
                    static fn (LogEntry $entry): bool => (! filled($level) || $entry->level === $level)
                        && (! filled($search) || mb_stripos($entry->text, (string) $search) !== false),
                ));
                $perPage = max(1, (int) $recordsPerPage);
                $items = array_slice($entries, (max(1, (int) $page) - 1) * $perPage, $perPage);

                return new LengthAwarePaginator(
                    collect($items)->mapWithKeys(static fn (LogEntry $entry): array => [$entry->id => $entry->toArray()])->all(),
                    count($entries),
                    $perPage,
                    (int) $page,
                );
            })
            ->searchable()
            ->searchPlaceholder(__('system_log.search'))
            // Wie die übrigen Tabellen: nur so breit wie der Inhalt, höchstens so breit wie der Platz.
            ->extraAttributes(['class' => 'saft-fit-table'])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('time')->label(__('system_log.time'))->fontFamily('mono')->size('sm'),
                TextColumn::make('level')
                    ->label(__('system_log.level'))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR' => 'danger',
                        'WARNING' => 'warning',
                        'NOTICE', 'INFO' => 'info',
                        default => 'gray',
                    })
                    ->description(static fn (array $record): string => (string) $record['environment']),
                // Lange Meldungen (auch Pfade ohne Leerzeichen) umbrechen statt die Tabelle zu verbreitern.
                TextColumn::make('message')->label(__('system_log.message'))->wrap()->limit(300)
                    ->extraAttributes(['class' => 'saft-log-message']),
                TextColumn::make('reference')->label(__('system_log.reference'))->fontFamily('mono')->placeholder('–'),
            ])
            ->filters([
                SelectFilter::make('level')
                    ->label(__('system_log.level'))
                    ->options(array_combine(LogReader::LEVELS, LogReader::LEVELS)),
            ])
            ->recordActions(RowIcons::of([
                Action::make('show')
                    ->label(__('system_log.show'))
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->modalHeading(static fn (array $record): string => $record['time'].' · '.$record['level'])
                    ->modalContent(fn (array $record): View => view('filament.system-log.entry', ['text' => $this->reader()->find($this->file, (string) $record['id'])?->text ?? __('system_log.gone')]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('system_log.close'))
                    ->modalWidth(Width::SixExtraLarge),
            ]))
            ->emptyStateHeading(__('system_log.empty'));
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        $files = $this->reader()->files();

        return [
            Action::make('file')
                ->label(fn (): string => __('system_log.file', ['file' => basename((string) $this->reader()->path($this->file))]))
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(count($files) > 1)
                ->schema([
                    Select::make('file')
                        ->label(__('system_log.choose_file'))
                        ->options(array_combine($files, $files))
                        ->default(basename((string) $this->reader()->path($this->file)))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->redirect(static::getUrl(['file' => (string) $data['file']]), navigate: true);
                }),
            Action::make('download')
                ->label(__('system_log.download'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible($files !== [])
                ->action(function (): StreamedResponse {
                    abort_unless(static::canAccess(), 403);
                    $text = $this->reader()->tail($this->file);
                    $name = pathinfo((string) $this->reader()->path($this->file), PATHINFO_FILENAME);

                    return response()->streamDownload(static function () use ($text): void {
                        echo $text;
                    }, $name.'_'.now()->format('Y-m-d_Hi').'.log', ['Content-Type' => 'text/plain; charset=UTF-8']);
                }),
        ];
    }
}
