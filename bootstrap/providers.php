<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    // TelescopeServiceProvider is registered conditionally in
    // AppServiceProvider::register() only when the laravel/telescope
    // package is installed (require-dev). Listing it here breaks
    // `composer install --no-dev` on production because Laravel's
    // package:discover tries to load the class before --no-dev has
    // dropped Telescope from the autoloader.
];
