<?php

use App\Http\Controllers\ActiveRepositoryController;
use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\BackupDownloadController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;

/*
|--------------------------------------------------------------------------
| Test-only authentication shortcut (browser E2E)
|--------------------------------------------------------------------------
|
| Pest browser tests drive a real Chromium, so $this->actingAs() (which only
| sets auth in the test process) does not authenticate the browser session.
| This route logs a user in by id and is registered ONLY in the `testing`
| environment — it never exists in local/staging/production. Browser tests
| call it once to obtain a real authenticated session cookie.
*/
if (app()->environment('testing')) {
    Route::get('/__test-login__/{user}', function (User $user) {
        Auth::login($user);

        return redirect('/admin');
    })->middleware('web');
}

// Default landing → Filament admin login.
// The bundled welcome.blade.php pulls fonts from fonts.bunny.net (CDN) and an
// SVG from laravel.com — both forbidden by RFQ §15 (no third-party assets at
// runtime) and blocked by AppPolicy CSP. The admin panel is the only intended
// entry point for this deliverable; everything else is unused scaffolding.
Route::redirect('/', '/admin/login');

/*
|--------------------------------------------------------------------------
| Health endpoint (RFQ-2026-06 §3.4.1 — non-functional observability)
|--------------------------------------------------------------------------
|
| Public JSON health probe consumed by NAF IT monitoring and uptime checks.
| Returns the latest cached results from the four checks registered in
| AppServiceProvider::registerHealthChecks() (DB, disk, schedule, backups).
| Refreshed every 5 minutes by the `health:check` scheduled command.
*/
Route::get('/health', HealthCheckJsonResultsController::class)->name('health');

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

/*
|--------------------------------------------------------------------------
| Active repository switcher (RFQ Wave 2 Task 10 — Submission §4.3.3)
|--------------------------------------------------------------------------
|
| POST target for the topbar repository <select>. Persists the chosen active
| repository (null = "All repositories") into the session (+ user prefs mirror)
| and redirects back. Authenticated panel session required.
*/
Route::post('/admin/active-repository', [ActiveRepositoryController::class, 'update'])
    ->middleware(['web', 'auth'])
    ->name('active-repository.update');

/*
|--------------------------------------------------------------------------
| Backup Center — authenticated download of a backup archive (RFQ §3.1.8)
|--------------------------------------------------------------------------
|
| GET /admin/backups/download?disk=<disk>&path=<file.zip>
|
| Streams a single .zip backup from a configured destination disk. Gated to
| admin / super_admin inside the controller and protected against directory
| traversal (disk allow-list, .zip-only, basename rebuild). Lives behind the
| panel's web + auth middleware so an authenticated session is required.
*/
Route::get('/admin/backups/download', BackupDownloadController::class)
    ->middleware(['web', 'auth'])
    ->name('backups.download');

/*
|--------------------------------------------------------------------------
| Attachment download — authenticated, policy-checked (F032)
|--------------------------------------------------------------------------
|
| GET /admin/attachments/{media}
|
| Streams a single spatie/medialibrary attachment from the private `media`
| disk. The file is never world-readable (no public /storage URL); this route
| is the only egress. The controller resolves the owning model (Accession /
| Document) and authorizes the user with the owner's `view` policy — which,
| via RepositoryScope, also blocks cross-tenant access. Behind the panel's
| web + auth middleware so an authenticated session is required.
*/
Route::get('/admin/attachments/{media}', AttachmentDownloadController::class)
    ->middleware(['web', 'auth'])
    ->name('attachments.download');
