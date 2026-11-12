<?php

declare(strict_types=1);

use App\Filament\Pages\ImportWizard;
use App\Filament\Pages\Reports\RasReconciliationReport;
use App\Filament\Pages\Settings\BrandingPage;
use App\Filament\Resources\AuditResource\Pages\ListAudits;
use App\Filament\Resources\BackupDestinationResource\Pages\ListBackupDestinations;
use App\Models\User;
use App\Settings\BrandingSettings;
use App\Support\PagePurposes;
use Filament\Schemas\Components\Wizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * 2026-11-12, found while rewriting the manual: help texts that said something
 * the code does not do.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('super_admin');
    $this->actingAs($this->admin);
});

it('the Import Wizard step 1 names Subseries and what a document row needs', function (): void {
    $form = Livewire::test(ImportWizard::class)->instance()->form;
    $wizard = collect($form->getComponents())->first(fn ($c): bool => $c instanceof Wizard);
    $description = (string) $wizard->getChildComponents()[0]->getDescription();

    expect($description)->toContain('Subseries → Authorities → Locations → Batches → Boxes → Documents')
        ->toContain('a missing batch is created')
        ->not->toContain('Series → Authorities → Batches');
});

it('the Audit log "About this page" describes filter rules, not From / To', function (): void {
    $body = PagePurposes::for(ListAudits::class)['body'] ?? '';

    expect($body)->toContain('filter rules')
        ->not->toContain('From / To');
});

it('the RAS reconciliation example is an invented reference', function (): void {
    $body = PagePurposes::for(RasReconciliationReport::class)['body'] ?? '';
    $view = (string) file_get_contents(resource_path('views/filament/pages/reports/ras-reconciliation.blade.php'));

    expect($body)->toContain('ARK/123-9')->not->toContain('ARK/054-4')
        ->and($view)->toContain('ARK/123-9')->not->toContain('ARK/054-4');
});

it('the backup destination "default" help no longer promises a highlight in the Backup Center', function (): void {
    $body = PagePurposes::for(ListBackupDestinations::class)['body'] ?? '';
    $source = (string) file_get_contents(app_path('Filament/Resources/BackupDestinationResource.php'));

    expect($body)->not->toContain('highlighted in the Backup Center')
        ->and($source)->not->toContain('highlighted in the Backup Center')
        ->and($source)->toContain('every active destination receives each backup');
});

it('the Branding page says removing the logo restores the default NAF logo, and where each logo shows', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('branding/logo.png', 'fake-image-content');
    $settings = app(BrandingSettings::class);
    $settings->logo_path = 'branding/logo.png';
    $settings->save();

    Livewire::test(BrandingPage::class)
        ->assertSee('the default NAF logo is shown again')
        ->assertSee('The sidebar and the top bar always show the white NAF logo')
        ->assertDontSee('shown as text');

    expect(PagePurposes::for(BrandingPage::class)['body'] ?? '')->not->toContain('brand name as text');
});
