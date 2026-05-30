<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Feedback1 Wave C1.5 — seed the concrete NRA location values the client
 * dictated: "Site can be eliminated and we will have ex: St Chris – Archive 1,
 * St Chris – Archive 2, St Paul – Cataloguing etc. and RAS."
 *
 * Idempotent: every row is `firstOrCreate`d keyed on (repository_id, name) so
 * running the seeder twice never produces duplicates. It does NOT touch or
 * replace any pre-existing location rows.
 *
 * These are global locations (repository_id = null) — they are physical NRA
 * sites/areas shared across tenants, matching the Location model's documented
 * "global location" notion. `code` is left null (the table's unique key is
 * (repository_id, code); leaving code null avoids colliding with any existing
 * coded global location).
 *
 * NOT wired into the default DatabaseSeeder run — it is registered behind an
 * explicit guard there and is meant to be invoked deliberately
 * (`db:seed --class=NraLocationSeeder`) so it never fires during a generic
 * `db:seed`.
 */
class NraLocationSeeder extends Seeder
{
    /**
     * name => type (one of Location::TYPES).
     *
     * @var array<string, string>
     */
    public const LOCATIONS = [
        'St Chris – Archive 1' => 'repository',
        'St Chris – Archive 2' => 'repository',
        'St Paul – Cataloguing' => 'work_area',
        'RAS' => 'repository',
    ];

    public function run(): void
    {
        foreach (self::LOCATIONS as $name => $type) {
            Location::firstOrCreate(
                ['repository_id' => null, 'name' => $name],
                ['type' => $type, 'is_active' => true],
            );
        }
    }
}
