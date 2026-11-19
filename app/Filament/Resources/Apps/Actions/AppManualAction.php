<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Actions;

use App\Filament\Pages\DocumentationPage;
use App\Models\App;
use App\Models\AppManual;
use App\Support\AppLocale;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * Handbuch einer App hochladen (B-107, Etappe 2): eine Markdown-Datei je Sprache, ersetzen oder entfernen. Es erscheint
 * unter „Dokumentation“ und im Hilfe-Assistenten, solange die App die aktuelle ist; fehlt eine Sprache, gilt die
 * Vorgabesprache.
 */
final class AppManualAction
{
    /** Höchstgrösse je Datei in KB. */
    private const int MAX_KB = 2048;

    public static function make(): Action
    {
        return Action::make('manual')
            ->label(__('apps.manual.action'))
            ->icon('heroicon-o-book-open')
            ->authorize(static fn (): bool => auth()->user()?->can('apps.manage') ?? false)
            ->modalHeading(fn (App $record): string => __('apps.manual.heading', ['app' => (string) $record->name]))
            ->modalDescription(__('apps.manual.description'))
            ->modalSubmitActionLabel(__('apps.manual.submit'))
            ->schema(static function (App $record): array {
                $existing = $record->manuals()->pluck('locale')->all();
                $fields = [];

                foreach (AppLocale::labels() as $locale => $language) {
                    $fields[] = Grid::make(2)->schema([
                        FileUpload::make("files.{$locale}")
                            ->label($language)
                            ->helperText(in_array($locale, $existing, true) ? __('apps.manual.present') : __('apps.manual.missing'))
                            ->acceptedFileTypes(['text/markdown', 'text/plain', 'text/x-markdown'])
                            ->maxSize(self::MAX_KB)
                            ->storeFiles(false),
                        Toggle::make("remove.{$locale}")
                            ->label(__('apps.manual.remove'))
                            ->inline(false)
                            ->visible(in_array($locale, $existing, true)),
                    ]);
                }

                return [Text::make(__('apps.manual.hint')), ...$fields];
            })
            ->action(static function (App $record, array $data): void {
                $changed = [];

                foreach (AppLocale::labels() as $locale => $language) {
                    // Ein einzelnes Upload-Objekt nicht mit (array) umwandeln – das ergäbe seine Eigenschaften.
                    $file = collect(Arr::wrap($data['files'][$locale] ?? null))->first(static fn (mixed $f): bool => $f instanceof UploadedFile);

                    if ($file instanceof UploadedFile) {
                        $content = (string) file_get_contents((string) $file->getRealPath());

                        if (! mb_check_encoding($content, 'UTF-8')) {
                            Notification::make()->danger()->title(__('apps.manual.not_utf8', ['language' => $language]))->send();

                            continue;
                        }

                        AppManual::query()->updateOrCreate(['app_id' => $record->id, 'locale' => $locale], ['content' => $content]);
                        $changed[] = $locale.': '.$file->getClientOriginalName();
                    } elseif ((bool) ($data['remove'][$locale] ?? false)) {
                        AppManual::query()->where('app_id', $record->id)->where('locale', $locale)->delete();
                        $changed[] = $locale.': –';
                    }
                }

                if ($changed === []) {
                    return;
                }

                activity('apps')->performedOn($record)->causedBy(auth()->user())->event('manual_changed')
                    ->withChanges(['attributes' => ['handbuch' => $changed]])->log('manual_changed');

                Notification::make()->success()->title(__('apps.manual.saved'))
                    ->body(__('apps.manual.saved_body', ['url' => DocumentationPage::getUrl()]))->send();
            });
    }
}
