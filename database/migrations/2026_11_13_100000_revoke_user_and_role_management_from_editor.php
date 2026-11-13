<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/*
 * Security audit 2026-11-13: InitialDataSeeder gave the editor (ReadingRoom)
 * role every create_ / update_ / view_ permission, user and role management
 * included, so an editor could create an admin account, reset an admin's
 * password or rewrite a role. The seeder no longer does; this takes the
 * same permissions away from the editor role already in the database.
 * Only the editor role changes: admin, viewer and roles made in the role
 * editor keep what they have.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $editorId = DB::table('roles')->where('name', 'editor')->where('guard_name', 'web')->value('id');

        if ($editorId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->pluck('name', 'id')
            ->filter(fn (string $name): bool => str_ends_with($name, '_user') || str_ends_with($name, '_role'))
            ->keys();

        DB::table('role_has_permissions')
            ->where('role_id', $editorId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Not restored: giving an editor account management back is a
        // decision for the role editor, not for a rollback.
    }
};
