<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Client 2026-11-05, issue 13: "Power actions options cut off when few records"
|--------------------------------------------------------------------------
| The table's box clipped the menu at its bottom edge. With one row and a menu
| longer than the box, every item must be on screen, reachable and on top.
*/

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function pam_admin(): User
{
    $repo = Repository::factory()->create();
    $admin = bl_actor('super_admin', $repo);

    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => 'PAM-1']);
    Document::factory()->create(['repository_id' => $repo->id, 'batch_id' => $batch->id, 'current_box_id' => $box->id]);

    return $admin;
}

/** Selects the row and opens the menu. */
function pam_open(object $page): void
{
    $page->wait(1)
        ->click('tbody input.fi-ta-record-checkbox')->wait(1)
        ->click('Power actions')->wait(1);
}

/**
 * For the open menu: is it inside the viewport, and is each item, once the menu
 * is scrolled to it (the panel only, nothing else), inside the viewport and the
 * topmost element at its centre?
 */
function pam_measure(object $page): array
{
    return $page->script(<<<'JS'
        () => {
            const panel = [...document.querySelectorAll('.fi-dropdown-panel')]
                .find((p) => getComputedStyle(p).display !== 'none' && p.getBoundingClientRect().height > 0);
            if (!panel) return { open: false, bad: ['no open menu'], items: 0 };

            const inside = (r) => r.top >= 0 && r.left >= 0 && r.bottom <= window.innerHeight && r.right <= window.innerWidth;
            const items = [...panel.querySelectorAll('.fi-dropdown-list-item')].filter((i) => getComputedStyle(i).display !== 'none');
            const bad = [];

            for (const item of items) {
                const rect0 = item.getBoundingClientRect();
                const panelRect = panel.getBoundingClientRect();
                if (rect0.top < panelRect.top || rect0.bottom > panelRect.bottom) {
                    panel.scrollTop += rect0.top - panelRect.top;
                }
                const r = item.getBoundingClientRect();
                const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
                if (!inside(r) || !top || !item.contains(top)) {
                    bad.push(item.textContent.trim());
                }
            }

            return {
                open: true,
                items: items.length,
                bad,
                last: items.length ? items[items.length - 1].textContent.trim() : null,
                panelInside: inside(panel.getBoundingClientRect()),
                scrollable: panel.scrollHeight > panel.clientHeight,
            };
        }
    JS);
}

it('shows every Power actions item of a one-row list, on top and on screen', function (string $url, string $mustHave): void {
    $page = bl_login(pam_admin())->navigate($url);
    pam_open($page);

    $menu = pam_measure($page);

    expect($menu['open'])->toBeTrue()
        ->and($menu['items'])->toBeGreaterThan(5)
        ->and($menu['panelInside'])->toBeTrue()
        ->and($menu['bad'])->toBe([]);
    $page->assertSee($mustHave)->assertNoJavascriptErrors();
})->with([
    'boxes' => ['/admin/boxes', 'Finish disinfestation'],
    'documents' => ['/admin/documents', 'Mark disinfested'],
]);

it('keeps a long menu scrollable within a short viewport', function (): void {
    $page = bl_login(pam_admin())->navigate('/admin/boxes');
    $page->resize(1000, 420);
    pam_open($page);

    $menu = pam_measure($page);

    expect($menu['panelInside'])->toBeTrue()
        ->and($menu['scrollable'])->toBeTrue()
        ->and($menu['bad'])->toBe([]);
});
