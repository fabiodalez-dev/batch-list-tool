<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Maps the internal Spatie/Shield role slugs to the RFQ-2026-06 / submission
 * display names (Administrator / ReadingRoom / General).
 *
 * Why this exists: the contract names the three operator roles
 * "Administrator", "ReadingRoom" and "General" (RFQ §3.3). The codebase
 * uses the Shield convention slugs (super_admin / admin / editor / viewer)
 * because they are wired into FieldPermissions, every Policy, the Shield
 * permission matrix and ~900 tests. Renaming the slugs for a cosmetic
 * match would be high-risk for zero functional gain — so the RFQ names are
 * surfaced here, at the UI layer, and the slugs stay as the stable
 * internal identifier. See docs/role-taxonomy.md for the full mapping.
 */
final class RoleLabels
{
    /**
     * Internal slug → RFQ display name.
     *
     * @var array<string, string>
     */
    public const array MAP = [
        'super_admin' => 'Administrator',
        'admin' => 'Administrator',
        'editor' => 'ReadingRoom',
        'viewer' => 'General',
    ];

    /**
     * RFQ display name for an internal role slug. Falls back to a
     * title-cased version of the slug for any role not in the map
     * (e.g. a future custom role).
     */
    public static function for(string $slug): string
    {
        return self::MAP[$slug] ?? str(str_replace('_', ' ', $slug))->title()->toString();
    }
}
