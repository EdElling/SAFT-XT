<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Documentation\Documentation;
use App\Models\User;
use Composer\InstalledVersions;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Online-Hilfe „Dokumentation“: Anwenderhandbuch, Häufige Fragen, für Admins das Administratorhandbuch,
 * dazu „Über SAFT“ (Version, Lizenz). Inhaltsverzeichnis je Handbuch, Suche über alle sichtbaren Handbücher.
 */
class DocumentationPage extends Page
{
    protected static ?string $slug = 'documentation';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.documentation';

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    public function getTitle(): string|Htmlable
    {
        return __('documentation.title');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $visible = Documentation::visibleFor($user instanceof User ? $user : null);

        return [
            'docs' => collect($visible)->mapWithKeys(fn (string $doc): array => [$doc => [
                'label' => __('documentation.docs.'.$doc),
                'language' => Documentation::language($doc),
                'sections' => Documentation::sections($doc, $visible),
            ]])->all(),
            'version' => (string) config('saft.version'),
            'versions' => [
                'Laravel' => app()->version(),
                'Filament' => InstalledVersions::getPrettyVersion('filament/filament') ?? '–',
                'PHP' => PHP_VERSION,
            ],
            'license' => (string) @file_get_contents(base_path('LICENSE')),
            'notices' => Documentation::thirdPartyNotices(),
        ];
    }
}
