<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FiscalServiceProvider;

return [
    AppServiceProvider::class,
    FiscalServiceProvider::class,
    AdminPanelProvider::class,
];
