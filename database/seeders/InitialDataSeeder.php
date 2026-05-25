<?php

namespace Database\Seeders;

use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
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
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin      = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $editor     = Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);
        $viewer     = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);

        // Assign Shield-generated permissions per role (admin = all; editor = view+create+update; viewer = view only)
        $allPerms = \Spatie\Permission\Models\Permission::pluck('name')->all();
        $admin->syncPermissions($allPerms);
        $editor->syncPermissions(
            collect($allPerms)->filter(fn ($p) => str_starts_with($p, 'view_') || str_starts_with($p, 'create_') || str_starts_with($p, 'update_') || str_starts_with($p, 'reorder_'))->all()
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
