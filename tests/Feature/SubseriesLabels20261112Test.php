<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\DocumentsByBatchReport;
use App\Filament\Pages\Reports\DocumentsByCreatorReport;
use App\Filament\Pages\Reports\DocumentsBySeriesReport;
use App\Filament\Pages\Reports\MassMigrationReport;
use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Filament\Pages\Reports\StockTakeReport;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\ImportProfileResource;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
 * 2026-11-12: the Documents list headed the column "Series" while the document
 * page says "Subseries" for the same field; several reports did the same.
 * "Series (with its subseries)" on Stock take and Mass migration selects a
 * whole branch of the hierarchy, so it keeps its name.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $user = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $user->assignRole('super_admin');
    $this->actingAs($user);
});

it('heads the Documents list column "Subseries", like the document page', function (): void {
    $table = Livewire::test(ListDocuments::class)->instance()->getTable();

    expect($table->getColumn('series.code')?->getLabel())->toBe('Subseries');
});

it('calls the document subseries filter and column "Subseries" on the reports', function (string $report): void {
    $table = Livewire::test($report)->instance()->getTable();

    expect($table->getFilter('series_id')?->getLabel())->toBe('Subseries');

    if ($report === PendingDisinfestationReport::class) {
        expect($table->getColumn('series.code')?->getLabel())->toBe('Subseries');
    }
})->with([
    DocumentsByCreatorReport::class,
    DocumentsByBatchReport::class,
    DocumentsBySeriesReport::class,
    PendingDisinfestationReport::class,
]);

it('keeps "Series (with its subseries)" where a whole branch is selected', function (): void {
    expect(Livewire::test(MassMigrationReport::class)->instance()->getTable()->getFilter('series')?->getLabel())
        ->toBe('Series (with its subseries)');

    // Stock take shows that filter only in its documents view.
    expect((string) file_get_contents((new ReflectionClass(StockTakeReport::class))->getFileName()))
        ->toContain("->label('Series (with its subseries)')");
});

it('names the subseries import type "Subseries" in Import profiles', function (): void {
    expect(ImportProfileResource::importTypeOptions())->toContain('Subseries')
        ->not->toContain('Series');
});
