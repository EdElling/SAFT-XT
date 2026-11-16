<?php

declare(strict_types=1);
use App\Providers\AppServiceProvider;
use App\Providers\BrandingServiceProvider;
use App\Providers\ErrorHandlingServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    BrandingServiceProvider::class,
    ErrorHandlingServiceProvider::class,
    AdminPanelProvider::class,
];
