<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reports\Pages;

use App\DynamicData\Export\ExportFormat;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use App\Models\User;
use App\Reports\Export\ReportExporter;
use App\Reports\ReportDescriber;
use App\Reports\ReportPresenter;
use App\Reports\ReportRunner;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Ergebnis einer gespeicherten Auswertung, ausgeführt mit den Datenrechten der Person. */
class ViewReport extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ReportResource::class;

    protected string $view = 'filament.reports.view';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(auth()->user()?->can('view', $this->record) ?? false, 403);
    }

    public function getTitle(): string|Htmlable
    {
        return (string) $this->getRecord()->getAttribute('name');
    }

    /** @return array<string, string> Auswertungen › Name (ohne doppelten Namen) */
    public function getBreadcrumbs(): array
    {
        return [ReportResource::getUrl('index') => ReportResource::getBreadcrumb(), '' => (string) $this->getTitle()];
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getRecord()->getAttribute('description');
    }

    private function exportAction(ExportFormat $format): Action
    {
        return Action::make('export_'.$format->value)
            ->label($format->label())
            ->action(function () use ($format): ?StreamedResponse {
                /** @var Report $report */
                $report = $this->getRecord();

                try {
                    $result = app(ReportRunner::class)->run($report, $this->currentUser());
                } catch (BusinessRuleException $e) {
                    Notification::make()->title(__('reports.ui.cannot_run'))->body($e->getMessage())->danger()->send();

                    return null;
                }

                $path = app(ReportExporter::class)->write($result, $format, $report->name);

                activity('data')
                    ->performedOn($report)
                    ->event('exported')
                    ->withChanges(['attributes' => ['format' => $format->value, 'zeilen' => count($result->rows), 'gekuerzt' => $result->truncated]])
                    ->log('exported');

                return response()->streamDownload(
                    static function () use ($path): void {
                        readfile($path);
                        unlink($path);
                    },
                    Str::slug($report->name, '_').'_'.now()->format('Y-m-d_Hi').'.'.$format->value,
                    ['Content-Type' => $format->contentType()],
                );
            });
    }

    private function isPinned(): bool
    {
        return $this->currentUser()->dashboardReports()->whereKey($this->getRecord()->getKey())->exists();
    }

    private function currentUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var Report $report */
        $report = $this->getRecord();
        $user = auth()->user();

        $view = app(ReportPresenter::class)->present($report, $user instanceof User ? $user : null);

        return [...$view, 'sentence' => $view['result'] !== null ? app(ReportDescriber::class)->describe($view['result']->report) : null];
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make(array_map(fn (ExportFormat $format): Action => $this->exportAction($format), ExportFormat::cases()))
                ->label(__('reports.export.label'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->button(),
            Action::make('pin')
                ->label(fn (): string => $this->isPinned() ? __('reports.dashboard.unpin') : __('reports.dashboard.pin'))
                ->icon(fn (): string => $this->isPinned() ? 'heroicon-s-star' : 'heroicon-o-star')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('widgets.reports') ?? false)
                ->action(function (): void {
                    $user = $this->currentUser();
                    $report = $this->getRecord();

                    if ($this->isPinned()) {
                        $user->dashboardReports()->detach($report);
                        Notification::make()->title(__('reports.dashboard.unpinned'))->success()->send();

                        return;
                    }

                    $user->dashboardReports()->attach($report, ['sort_order' => ((int) $user->dashboardReports()->max('dashboard_reports.sort_order')) + 1]);
                    Notification::make()->title(__('reports.dashboard.pinned'))->success()->send();
                }),
            Action::make('refresh')
                ->label(__('reports.ui.refresh'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => null),
            EditAction::make(),
        ];
    }
}
