<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\TelescopeServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    TelescopeServiceProvider::class,
];
