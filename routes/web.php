<?php

use Illuminate\Support\Facades\Route;

// Default landing → Filament admin login.
// The bundled welcome.blade.php pulls fonts from fonts.bunny.net (CDN) and an
// SVG from laravel.com — both forbidden by RFQ §15 (no third-party assets at
// runtime) and blocked by AppPolicy CSP. The admin panel is the only intended
// entry point for this deliverable; everything else is unused scaffolding.
Route::redirect('/', '/admin/login');

/*
|--------------------------------------------------------------------------
| Two-factor authentication challenge view (RFQ §3.1.7 hardening)
|--------------------------------------------------------------------------
|
| Fortify's view-bearing GET routes are disabled (`fortify.views = false`)
| because Filament owns the panel's login/profile UI. We still need a GET
| endpoint where a mid-login user (Filament has stashed their id in
| `login.id` and logged them out) can enter their 6-digit TOTP code or a
| recovery code — Fortify's POST `/two-factor-challenge` consumes that
| form. We render a small CSP-compliant Blade view here.
*/
Route::get('/two-factor-challenge', function () {
    abort_unless(session()->has('login.id'), 419, 'No two-factor challenge in progress.');

    return view('auth.two-factor-challenge');
})
    ->middleware(['web', 'guest:web'])
    ->name('two-factor.login');
