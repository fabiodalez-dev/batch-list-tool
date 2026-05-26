<?php

use App\Filament\Resources\AuditResource;
use App\Filament\Resources\AuditResource\Pages\ListAudits;
use App\Filament\Resources\AuditResource\Pages\ViewAudit;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    config(['audit.console' => true]);
});

it('is read-only — disables create/edit/delete', function () {
    expect(AuditResource::canCreate())->toBeFalse()
        ->and(AuditResource::canDeleteAny())->toBeFalse();

    $row = Audit::create([
        'user_type' => User::class,
        'user_id' => User::factory()->create()->id,
        'event' => 'created',
        'auditable_type' => User::class,
        'auditable_id' => 1,
    ]);

    expect(AuditResource::canEdit($row))->toBeFalse()
        ->and(AuditResource::canDelete($row))->toBeFalse();
});

it('navigation label + icon + group are set for Operations group', function () {
    expect(AuditResource::getNavigationLabel())->toBe('Audit log')
        ->and(AuditResource::getNavigationGroup())->toBe('Operations')
        ->and(AuditResource::getNavigationSort())->toBe(90);
});

it('points to the correct Eloquent model (OwenIt Audit)', function () {
    expect(AuditResource::getModel())->toBe(Audit::class);
});

it('exposes index + view pages — no create/edit routes', function () {
    $pages = AuditResource::getPages();

    expect($pages)->toHaveKeys(['index', 'view'])
        ->and($pages)->not->toHaveKey('create')
        ->and($pages)->not->toHaveKey('edit');
});

it('ListAudits page class extends Filament ListRecords', function () {
    expect(is_subclass_of(ListAudits::class, ListRecords::class))->toBeTrue();
});

it('ViewAudit page class extends Filament ViewRecord', function () {
    expect(is_subclass_of(ViewAudit::class, ViewRecord::class))->toBeTrue();
});

it('returns the parent Eloquent query unmodified (placeholder for future tenant scope)', function () {
    User::factory()->create();
    $count = Audit::count();
    expect(AuditResource::getEloquentQuery()->count())->toBe($count);
});
