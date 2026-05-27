<?php

declare(strict_types=1);

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

        $visible = Document::query()->pluck('repository_id')->unique()->all();
        expect($visible)->each->toBe($repoA->id);
    });

    it('super_admin bypasses RepositoryScope and sees all repositories')->todo('Feature\\SecurityBaseline\\MultiTenantScopeTest');
    it('BelongsToRepository trait blocks cross-tenant create with DomainException')->todo('Feature\\SecurityBaseline\\MultiTenantScopeTest');
    it('Box + BoxMovement scopes follow through batch→repository chain')->todo('Feature\\SecurityBaseline\\MultiTenantScopeTest');
})->group('rfq:3.5');
