<?php

namespace Database\Seeders;

use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class InitialDataSeeder extends Seeder
{
    public function run(): void
    {
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

        // ----- Series (reference data, sample subset of RFQ) -----
        $seriesData = [
            ['code' => 'R',   'title' => 'Register Copies (Registro)',          'is_wills_series' => false],
            ['code' => 'REG', 'title' => 'Registers Private Practice',          'is_wills_series' => false],
            ['code' => 'RWL', 'title' => 'Registers Private Practice — Wills',  'is_wills_series' => true],
            ['code' => 'OWL', 'title' => 'Originals — Wills',                   'is_wills_series' => true],
            ['code' => 'O',   'title' => 'Originals (Minutari)',                'is_wills_series' => false],
        ];
        foreach ($seriesData as $row) {
            Series::firstOrCreate(['code' => $row['code']], $row + ['is_active' => true]);
        }

        // ----- Authorities (small sample from RFQ docs) -----
        $authorities = [
            ['identifier' => 'R1',   'alternative_identifier' => 'MS511', 'surname' => 'Abela',     'practice_dates_start' => 1607, 'practice_dates_end' => 1629],
            ['identifier' => 'R12',  'alternative_identifier' => 'MS523', 'surname' => 'Albano',    'practice_dates_start' => 1582, 'practice_dates_end' => 1636],
            ['identifier' => 'R110', 'alternative_identifier' => 'MS634', 'surname' => 'Buttigieg', 'practice_dates_start' => 1759, 'practice_dates_end' => 1798],
            ['identifier' => 'R140', 'alternative_identifier' => 'MS670', 'surname' => 'Canciur',   'practice_dates_start' => 1499, 'practice_dates_end' => 1531],
        ];
        foreach ($authorities as $a) {
            Authority::firstOrCreate(['identifier' => $a['identifier']], $a + ['entity_type' => 'PERSON']);
        }

        // ----- Batches (sample) -----
        Batch::firstOrCreate(['batch_number' => 1],  ['description' => 'Main collection batch 1',  'type' => 'MAIN_COLLECTION',  'repository_id' => $main->id]);
        Batch::firstOrCreate(['batch_number' => 2],  ['description' => 'Main collection batch 2',  'type' => 'MAIN_COLLECTION',  'repository_id' => $main->id]);
        Batch::firstOrCreate(['batch_number' => 50], ['description' => 'Wills — reserved',         'type' => 'MAIN_COLLECTION',  'repository_id' => $main->id]);
        Batch::firstOrCreate(['batch_number' => 30], ['description' => 'Notary accession batch',   'type' => 'NOTARY_ACCESSION', 'repository_id' => $main->id]);

        // ----- Boxes (sample) -----
        $batch1 = Batch::where('batch_number', 1)->first();
        $batch50 = Batch::where('batch_number', 50)->first();

        $rasBox = Box::firstOrCreate(
            ['barcode' => 'AA40822'],
            ['box_type' => 'RAS', 'box_number' => '1-20', 'batch_id' => $batch1->id, 'barcode_status' => 'IN']
        );
        Box::firstOrCreate(
            ['barcode' => 'NRA-001'],
            ['box_type' => 'IN_SITU', 'box_number' => '1', 'batch_id' => $batch1->id, 'parent_box_id' => $rasBox->id, 'barcode_status' => 'IN']
        );
        Box::firstOrCreate(
            ['barcode' => 'WILLS-001'],
            ['box_type' => 'IN_SITU', 'box_number' => '1', 'batch_id' => $batch50->id, 'parent_box_id' => $rasBox->id, 'barcode_status' => 'IN']
        );

        $this->command->info('');
        $this->command->info('═══════════════════════════════════════════════════');
        $this->command->info(' Admin user created:');
        $this->command->info('   Email:    admin@batchlist.local');
        $this->command->info('   Password: ChangeMe!Local2026');
        $this->command->info('   URL:      http://127.0.0.1:8000/admin');
        $this->command->info('═══════════════════════════════════════════════════');
    }
}
