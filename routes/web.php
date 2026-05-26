<?php

use Illuminate\Support\Facades\Route;

// Default landing → Filament admin login.
// The bundled welcome.blade.php pulls fonts from fonts.bunny.net (CDN) and an
// SVG from laravel.com — both forbidden by RFQ §15 (no third-party assets at
// runtime) and blocked by AppPolicy CSP. The admin panel is the only intended
// entry point for this deliverable; everything else is unused scaffolding.
Route::redirect('/', '/admin/login');
