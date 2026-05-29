<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * Reusable smoke test: every parameter-free GET route in the `admin` panel
 * must render without a server error (HTTP < 500) for a super_admin.
 *
 * This guards against the class of regressions that unit tests miss but that
 * surface the moment a page is opened in the browser — e.g. a mis-cased
 * import-action namespace, or a Filament Page declaring `protected static
 * $view` (illegal in Filament 5) which fatals the whole panel.
 *
 * It deliberately asserts only "not a 5xx": a 200 (rendered), 302 (redirect,
 * e.g. login when already authenticated) and 403 (gated page) are all fine —
 * we only care that the page does not blow up server-side.
 *
 * As new Resources/Pages are added they are picked up automatically (the test
 * enumerates the live route table), so no maintenance is needed per page.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('renders every parameter-free admin GET page without a server error', function () {
    // super_admin bypasses the repository scope and holds every permission,
    // so it can reach the widest set of pages. must_change_password defaults
    // to false, so the force-password middleware does not interfere.
    $user = bl_actor('super_admin');
    $this->actingAs($user);

    $checked = 0;
    $failures = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = $route->uri();

        // Panel pages only.
        if ($uri !== 'admin' && ! str_starts_with($uri, 'admin/')) {
            continue;
        }

        // Skip routes that require parameters (record views/edits, etc.) —
        // those need fixture models and are covered by their own tests.
        if (str_contains($uri, '{')) {
            continue;
        }

        $checked++;

        try {
            $status = $this->get('/' . $uri)->status();
        } catch (\Throwable $e) {
            $failures[] = sprintf('%s threw %s: %s', $uri, $e::class, $e->getMessage());

            continue;
        }

        if ($status >= 500) {
            $failures[] = sprintf('%s -> HTTP %d', $uri, $status);
        }
    }

    expect($checked)->toBeGreaterThan(10); // sanity: we actually walked the panel

    $this->assertSame(
        [],
        $failures,
        "These admin pages returned a server error:\n" . implode("\n", $failures),
    );
});
