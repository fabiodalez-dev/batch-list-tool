<?php

declare(strict_types=1);

namespace Tests\Compliance;

use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Lightweight fixtures shared by the RFQ-2026-06 compliance matrix.
 *
 * The full Feature suite uses much richer factories with realistic
 * relations; these helpers stay minimal so the compliance suite runs
 * in <60s end-to-end and gives a tight "does the contract still hold?"
 * signal on every push without paying for deep relation tree assembly.
 */
final class Helpers
{
    public static function role(string $name = 'super_admin'): User
    {
        foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $u = User::factory()->create([
            'email' => 'compl+' . uniqid() . '@test.local',
            'is_active' => true,
        ]);
        $u->assignRole($name);

        return $u;
    }

    public static function repo(?string $code = null): Repository
    {
        return Repository::factory()->create([
            'code' => $code ?? 'CP-' . substr(uniqid(), -6),
        ]);
    }

    public static function batch(int $repoId, ?int $number = null): Batch
    {
        do {
            $n = $number ?? random_int(2000, 8999);
            $number = null; // only honour the override once
        } while (in_array($n, [33, 34, 36], true)
            || Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', $n)->exists());

        return Batch::withoutGlobalScope(RepositoryScope::class)->create([
            'batch_number' => $n,
            'type' => 'MAIN_COLLECTION',
            'repository_id' => $repoId,
            'is_active' => true,
        ]);
    }

    public static function box(int $batchId, array $attrs = []): Box
    {
        return Box::create(array_merge([
            'box_type' => 'RAS',
            'box_number' => 'CB-' . strtoupper(substr(uniqid(), -6)),
            'batch_id' => $batchId,
            // F5: RAS boxes require a barcode (RFQ Feedback1 C2.1).
            'barcode' => 'CBC-' . strtoupper(substr(uniqid(), -6)),
            'barcode_status' => 'IN',
        ], $attrs));
    }

    public static function series(): Series
    {
        return Series::firstOrCreate(
            ['code' => 'CP-' . substr(uniqid(), -4)],
            ['title' => 'Compliance test series', 'is_active' => true],
        );
    }

    public static function authority(): Authority
    {
        return Authority::create([
            'identifier' => 'R-' . substr(uniqid(), -6),
            'surname' => 'Compl',
            'entity_type' => 'PERSON',
        ]);
    }

    public static function doc(int $repoId, int $seriesId, ?int $boxId = null, array $attrs = []): Document
    {
        return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
            'identifier' => 'CDOC-' . strtoupper(substr(uniqid(), -8)),
            'document_type' => 'Register',
            'series_id' => $seriesId,
            'repository_id' => $repoId,
            'current_box_id' => $boxId,
        ], $attrs));
    }
}
