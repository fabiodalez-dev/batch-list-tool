<?php

declare(strict_types=1);

use App\Models\Box;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Compliance\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/* ─── REQ-3.5 Multi-repository support ───────────────────────────── */
describe('REQ-3.5 Multi-tenant repository scoping', function () {
    test('a user in repoA cannot see documents from repoB through the scope', function () {
        $repoA = Helpers::repo('REPO-A');
        $repoB = Helpers::repo('REPO-B');
        $seriesA = Helpers::series();
        $seriesB = Helpers::series();
        Helpers::doc($repoA->id, $seriesA->id);
        Helpers::doc($repoB->id, $seriesB->id);

        $userA = Helpers::role('editor');
        $userA->repositories()->attach($repoA->id);
        $userA->default_repository_id = $repoA->id;
        $userA->save();
        $this->actingAs($userA);

        $visible = Document::query()->pluck('repository_id')->unique()->values()->all();
        // Guard against silent pass on empty result: a leak detection test
        // that finds zero documents at all proves nothing.
        expect($visible)
            ->toHaveCount(1)
            ->toContain($repoA->id)
            ->not->toContain($repoB->id);
    });

    test('admin override sees all repositories regardless of membership when nothing is explicitly selected', function () {
        // Three repos, each with one document. The admin is a MEMBER of only
        // two of them (repoA, repoB) and has repoA as their default — but the
        // admin oversight role MUST see all three, because nothing has been
        // explicitly selected via the switcher this session.
        $repoA = Helpers::repo('OVR-A');
        $repoB = Helpers::repo('OVR-B');
        $repoC = Helpers::repo('OVR-C');
        $series = Helpers::series();
        Helpers::doc($repoA->id, $series->id);
        Helpers::doc($repoB->id, $series->id);
        Helpers::doc($repoC->id, $series->id);

        $admin = Helpers::role('admin');
        $admin->repositories()->attach([$repoA->id, $repoB->id]);
        // A persisted/default preference must NOT silently narrow the admin:
        // only an EXPLICIT switcher selection may scope a privileged user.
        $admin->default_repository_id = $repoA->id;
        $admin->forceFill(['active_repository_id' => $repoA->id])->save();
        $this->actingAs($admin);

        $visible = Document::query()->pluck('repository_id')->unique()->values()->all();

        expect($visible)
            ->toHaveCount(3)
            ->toContain($repoA->id)
            ->toContain($repoB->id)
            ->toContain($repoC->id);

        // The through-batch scope (Box) is the path that actually honours an
        // active repo for privileged users — it must NOT be narrowed by a
        // stale/default value either. One box per repo → admin must see all 3.
        $boxA = Helpers::box(Helpers::batch($repoA->id)->id);
        $boxB = Helpers::box(Helpers::batch($repoB->id)->id);
        $boxC = Helpers::box(Helpers::batch($repoC->id)->id);

        $visibleBoxes = Box::query()
            ->whereIn('id', [$boxA->id, $boxB->id, $boxC->id])
            ->pluck('id')->all();

        expect($visibleBoxes)->toHaveCount(3);
    });

    it('super_admin bypasses RepositoryScope and sees all repositories')->todo('Feature\\SecurityBaseline\\MultiTenantScopeTest');
    it('BelongsToRepository trait blocks cross-tenant create with DomainException')->todo('Feature\\SecurityBaseline\\MultiTenantScopeTest');
    it('Box + BoxMovement scopes follow through batch→repository chain')->todo('Feature\\SecurityBaseline\\MultiTenantScopeTest');
})->group('rfq:3.5');
