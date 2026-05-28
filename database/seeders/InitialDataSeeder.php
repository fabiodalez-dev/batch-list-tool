<?php

namespace Database\Seeders;

use App\Models\Repository;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class InitialDataSeeder extends Seeder
{
    public function run(): void
    {
        // ----- Generate Filament Shield permissions FIRST so the role-permission sync below has something to assign -----
        $this->command->info('Generating Filament Shield permissions for all panels and resources ...');
        Artisan::call('shield:generate', ['--all' => true, '--panel' => 'admin']);
        Artisan::call('permission:cache-reset');

        // ----- Repositories -----
        $main = Repository::firstOrCreate(
            ['code' => 'NRA'],
            ['name' => 'Notarial Registers Archive (Main)', 'description' => 'Main repository, Valletta — NAF Malta.', 'is_active' => true]
        );

        Repository::firstOrCreate(
            ['code' => 'EXT'],
            ['name' => 'External / Other', 'description' => 'Externally managed accessions or special collections.', 'is_active' => true]
        );

        // ----- Roles (Shield's super_admin is created on the fly; we add operational roles) -----
        //
        // RFQ §3.3 / submission taxonomy mapping (see docs/role-taxonomy.md):
        //   Administrator  →  super_admin (+ admin)
        //   ReadingRoom    →  editor
        //   General        →  viewer
        // The internal slugs (super_admin/admin/editor/viewer) are the Shield/
        // Spatie convention used across FieldPermissions, policies and the test
        // suite. The RFQ-facing display names are surfaced in the UI via the
        // App\Support\RoleLabels helper; renaming the slugs themselves would
        // break the permission matrix + policy gates + ~900 tests for a purely
        // cosmetic gain, so the mapping is documented rather than renamed.
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $editor = Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);
        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);

        // RFQ §3.1.10 — Reports landing + 5 canned reports.
        // The reports are Filament Pages (not Resources), so shield:generate
        // does not auto-discover them. We seed a "virtual" resource called
        // `report` with the standard 12-op suffix matrix so the permission
        // names line up with the rest of the policy surface.
        foreach (['view_any', 'view', 'create', 'update', 'delete', 'delete_any',
            'force_delete', 'force_delete_any', 'restore', 'restore_any',
            'replicate', 'reorder'] as $op) {
            Permission::firstOrCreate(['name' => "{$op}_report", 'guard_name' => 'web']);
        }

        // Custom (non-Shield-default) permission: gates the workflow
        // transitions on DocumentFlag (acknowledge / resolve / dismiss)
        // separately from generic `update_document_flag`, so reviewers can
        // be allowed to close flags without editing their content.
        // See FlagsRelationManager::userCanResolve() and DocumentFlagPolicy.
        Permission::firstOrCreate(['name' => 'resolve_document_flag', 'guard_name' => 'web']);

        // Assign Shield-generated permissions per role (admin = all; editor = view+create+update; viewer = view only)
        $allPerms = Permission::pluck('name')->all();
        $admin->syncPermissions($allPerms);
        $editor->syncPermissions(
            collect($allPerms)->filter(fn ($p) => str_starts_with($p, 'view_') || str_starts_with($p, 'create_') || str_starts_with($p, 'update_') || str_starts_with($p, 'reorder_') || $p === 'resolve_document_flag')->all()
        );
        $viewer->syncPermissions(
            collect($allPerms)->filter(fn ($p) => str_starts_with($p, 'view_'))->all()
        );

        // ----- Admin user -----
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@batchlist.local'],
            [
                'name' => 'NRA Admin',
                'password' => Hash::make('ChangeMe!Local2026'),
                'default_repository_id' => $main->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $adminUser->assignRole('super_admin');
        $adminUser->repositories()->syncWithoutDetaching([$main->id => ['is_default' => true]]);

        // ----- Sample domain data is loaded by the `nra:import-samples` command, not here. -----
        // The seeder only sets up the bare minimum required for someone to log in.

        $this->command->info('');
        $this->command->info('═══════════════════════════════════════════════════');
        $this->command->info(' Admin user created:');
        $this->command->info('   Email:    admin@batchlist.local');
        $this->command->info('   Password: ChangeMe!Local2026');
        $this->command->info('   URL:      http://127.0.0.1:8000/admin');
        $this->command->info('═══════════════════════════════════════════════════');
    }
}
