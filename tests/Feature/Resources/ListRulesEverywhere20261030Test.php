<?php

declare(strict_types=1);

use App\Filament\Resources\AccessionResource\Pages\ListAccessions;
use App\Filament\Resources\AuditResource\Pages\ListAudits;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Filament\Resources\BackupDestinationResource\Pages\ListBackupDestinations;
use App\Filament\Resources\BatchResource;
use App\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Filament\Resources\BoxMovementResource\Pages\ListBoxMovements;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\DocumentFlagResource\Pages\ListDocumentFlags;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentTypeResource\Pages\ListDocumentTypes;
use App\Filament\Resources\ImportProfileResource\Pages\ListImportProfiles;
use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Filament\Resources\Lookups\BarcodeStatusResource\Pages\ListBarcodeStatuses;
use App\Filament\Resources\Lookups\BatchTypeResource\Pages\ListBatchTypes;
use App\Filament\Resources\Lookups\BoxTypeResource\Pages\ListBoxTypes;
use App\Filament\Resources\Lookups\CurrentBoxTypeResource\Pages\ListCurrentBoxTypes;
use App\Filament\Resources\Lookups\DigitisationStatusResource\Pages\ListDigitisationStatuses;
use App\Filament\Resources\Lookups\FlagTypeResource\Pages\ListFlagTypes;
use App\Filament\Resources\Lookups\LocationTypeResource\Pages\ListLocationTypes;
use App\Filament\Resources\PracticeResource\Pages\ListPractices;
use App\Filament\Resources\ReportTemplateResource\Pages\ListReportTemplates;
use App\Filament\Resources\RepositoryResource\Pages\ListRepositories;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\VolumeResource\Pages\ListVolumes;
use App\Filament\Support\MatchQueryBuilder;
use App\Models\Batch;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\ColumnLabels\ColumnLabels;
use Filament\QueryBuilder\Constraints\Constraint;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-10-30, "BatchListIssues30.docx", issues 3, 5 and 6: every list
 * filters like Documents — AND / OR above "Add rule", one rule per column —
 * and the Boxes Location rule matches the location the column shows.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    bl_seedShieldPermissions();
    $this->repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);
    $this->series = Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'repository_id' => $this->repo->id]);
});

/**
 * Columns whose value no rule can sensibly match, per list.
 *
 * @return array<class-string, list<string>>
 */
function lre_exceptions(): array
{
    return [
        // Text worked out per row: "Changes" from the old/new JSON values
        // (the "Field changed" rule finds a change), "Record" from the
        // model and id (the "Model" and "Record ID" rules).
        ListAudits::class => ['Changes', 'Record'],
    ];
}

/**
 * Columns whose rule has another name, per list: the Documents rules were
 * named in earlier rounds (client 2026-10-27/29) and links carry them.
 *
 * @return array<class-string, array<string, string>>
 */
function lre_aliases(): array
{
    return [
        ListDocuments::class => [
            'Identifier' => 'Document identifier',
            'Creator' => 'Creator (surname)',
            'Flags' => 'Flag',
            'Series' => ColumnLabels::get('document', 'series'),
            'Box' => 'Current box',
            'From' => 'From (year)',
            'To' => 'To (year)',
        ],
    ];
}

/**
 * @return array<string, array{class-string}>
 */
function lre_lists(): array
{
    $lists = [
        ListRepositories::class, ListLocations::class, ListSeries::class, ListBatches::class,
        ListBoxes::class, ListDocuments::class, ListAuthorities::class, ListAccessions::class,
        ListPractices::class, ListDocumentTypes::class, ListVolumes::class, ListBoxMovements::class,
        ListDocumentFlags::class, ListImportProfiles::class, ListReportTemplates::class, ListUsers::class,
        ListAudits::class, ListBackupDestinations::class,
        ListBarcodeStatuses::class, ListBatchTypes::class, ListBoxTypes::class, ListCurrentBoxTypes::class,
        ListDigitisationStatuses::class, ListFlagTypes::class, ListLocationTypes::class,
    ];

    return collect($lists)->mapWithKeys(fn (string $page): array => [class_basename($page) => [$page]])->all();
}

/**
 * One rule (`queryBuilder` filter) per list, as the URL / filterTable() shape.
 *
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function lre_rule(string $type, string $operator, array $settings = []): array
{
    return MatchQueryBuilder::urlFilters($type, $operator, $settings)['queryBuilder'];
}

it('holds only the rules (and the deleted-records filter) on every list', function (string $page): void {
    $table = Livewire::test($page)->instance()->getTable();
    $filters = $table->getFilters();

    expect($filters)->toHaveKey('queryBuilder')
        ->and($filters['queryBuilder'])->toBeInstanceOf(MatchQueryBuilder::class);

    foreach ($filters as $name => $filter) {
        if ($name === 'queryBuilder') {
            continue;
        }
        expect($filter)->toBeInstanceOf(TrashedFilter::class, "{$page}: filter [{$name}] is not a rule");
    }
})->with(lre_lists());

it('has a rule for every column the list can show', function (string $page): void {
    foreach (['box', 'batch', 'volume', 'document'] as $entity) {
        CustomFieldDefinition::create([
            'repository_id' => $this->repo->id, 'entity_type' => $entity, 'key' => 'past_ref',
            'label' => 'Past Reference Code', 'type' => 'text', 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    $table = Livewire::test($page)->instance()->getTable();
    $rules = collect($table->getFilter('queryBuilder')->getConstraints())
        ->map(fn (Constraint $c): string => mb_strtolower(trim($c->getLabel())))
        ->values()
        ->all();

    $missing = collect($table->getColumns())
        ->map(fn (Column $c): string => trim((string) $c->getLabel()))
        ->reject(fn (string $label): bool => $label === '' || in_array($label, lre_exceptions()[$page] ?? [], true))
        ->reject(fn (string $label): bool => in_array(mb_strtolower(lre_aliases()[$page][$label] ?? $label), $rules, true))
        ->values()
        ->all();

    expect($missing)->toBe([], $page . ' has no rule for: ' . implode(', ', $missing));
})->with(lre_lists());

/* ------------------------------------------------------------------ *
 |  Issue 3 — Boxes: the Location rule matches the Location column     |
 * ------------------------------------------------------------------ */

it('finds the boxes the Location column shows in a room: own location, "from documents", and the shelves inside', function (): void {
    $mould = qf_location($this->repo->id, ['name' => 'Mould Room']);
    $shelf = qf_location($this->repo->id, ['name' => 'Shelf 2', 'type' => 'shelf', 'parent_id' => $mould->id]);
    $other = qf_location($this->repo->id, ['name' => 'Strong Room']);
    $batch = Batch::factory()->create(['repository_id' => $this->repo->id]);

    $box = function (?Location $loc, array $docLocations) use ($batch): Box {
        $b = Box::factory()->create(['batch_id' => $batch->id, 'location_id' => $loc?->id]);
        foreach ($docLocations as $docLoc) {
            Document::factory()->create(['repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'current_box_id' => $b->id, 'location_id' => $docLoc?->id]);
        }
        // The box keeps no location of its own, as on the client's list.
        if ($loc === null) {
            Box::query()->whereKey($b->id)->update(['location_id' => null]);
        }

        return $b;
    };

    $own = $box($mould, []);
    $onShelf = $box($shelf, []);
    $fromDocuments = $box(null, [$mould, $mould, null]);
    $fromDocumentsOnShelf = $box(null, [$shelf]);
    $split = $box(null, [$mould, $other]);
    $elsewhere = $box($other, [$mould]);
    $nowhere = $box(null, []);

    // The screenshot's case: no location of its own, "from documents".
    expect(Box::find($fromDocuments->id)->location)->toBeNull()
        ->and(Box::find($fromDocuments->id)->sharedDocumentLocation()?->id)->toBe($mould->id);

    Livewire::test(ListBoxes::class)
        ->filterTable('queryBuilder', lre_rule('location', 'is', ['values' => [$mould->id]]))
        ->assertCanSeeTableRecords([$own, $onShelf, $fromDocuments, $fromDocumentsOnShelf])
        ->assertCanNotSeeTableRecords([$split, $elsewhere, $nowhere]);

    Livewire::test(ListBoxes::class)
        ->filterTable('queryBuilder', lre_rule('location', 'is.inverse', ['values' => [$mould->id]]))
        ->assertCanSeeTableRecords([$split, $elsewhere, $nowhere])
        ->assertCanNotSeeTableRecords([$own, $onShelf, $fromDocuments, $fromDocumentsOnShelf]);

    Livewire::test(ListBoxes::class)
        ->filterTable('queryBuilder', lre_rule('location', 'is', ['values' => [$shelf->id]]))
        ->assertCanSeeTableRecords([$onShelf, $fromDocumentsOnShelf])
        ->assertCanNotSeeTableRecords([$own, $fromDocuments, $split, $elsewhere, $nowhere]);
});

it('opens the Boxes list on the batch from the Batch page link', function (): void {
    $batchA = Batch::factory()->create(['repository_id' => $this->repo->id]);
    $batchB = Batch::factory()->create(['repository_id' => $this->repo->id]);
    $boxA = Box::factory()->create(['batch_id' => $batchA->id]);
    $boxB = Box::factory()->create(['batch_id' => $batchB->id]);

    parse_str((string) parse_url(BatchResource::boxesUrl($batchA), PHP_URL_QUERY), $query);

    expect($query['filters']['queryBuilder']['rules']['batch']['type'])->toBe('batch');

    Livewire::withQueryParams($query)
        ->test(ListBoxes::class)
        ->assertCanSeeTableRecords([$boxA])
        ->assertCanNotSeeTableRecords([$boxB]);
});

it('filters boxes by destroyed and by parent box', function (): void {
    $batch = Batch::factory()->create(['repository_id' => $this->repo->id]);
    $parent = Box::factory()->create(['batch_id' => $batch->id]);
    $assembledFrom = Box::factory()->create(['batch_id' => $batch->id]);
    $child = Box::factory()->create(['batch_id' => $batch->id, 'parent_box_id' => $parent->id]);
    $assembled = Box::factory()->create(['batch_id' => $batch->id]);
    $assembled->parents()->attach($assembledFrom->id);
    $destroyed = Box::factory()->create(['batch_id' => $batch->id, 'destroyed_at' => now()]);

    Livewire::test(ListBoxes::class)
        ->filterTable('queryBuilder', lre_rule('parent_box', 'is', ['values' => [$parent->id, $assembledFrom->id]]))
        ->assertCanSeeTableRecords([$child, $assembled])
        ->assertCanNotSeeTableRecords([$parent, $assembledFrom, $destroyed]);

    Livewire::test(ListBoxes::class)
        ->filterTable('queryBuilder', lre_rule('destroyed', 'yes'))
        ->assertCanSeeTableRecords([$destroyed])
        ->assertCanNotSeeTableRecords([$parent, $child]);

    Livewire::test(ListBoxes::class)
        ->filterTable('queryBuilder', lre_rule('destroyed', 'yes.inverse'))
        ->assertCanSeeTableRecords([$parent, $child])
        ->assertCanNotSeeTableRecords([$destroyed]);
});

/* ------------------------------------------------------------------ *
 |  Issue 5 — Locations: every column is a rule                        |
 * ------------------------------------------------------------------ */

it('filters locations by the Boxes and Documents counts', function (): void {
    $empty = qf_location($this->repo->id, ['name' => 'Empty']);
    $one = qf_location($this->repo->id, ['name' => 'One']);
    $three = qf_location($this->repo->id, ['name' => 'Three']);
    $batch = Batch::factory()->create(['repository_id' => $this->repo->id]);
    Box::factory()->create(['batch_id' => $batch->id, 'location_id' => $one->id]);
    Box::factory()->count(3)->create(['batch_id' => $batch->id, 'location_id' => $three->id]);
    Document::factory()->count(2)->create(['repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'location_id' => $empty->id]);

    Livewire::test(ListLocations::class)
        ->filterTable('queryBuilder', lre_rule('boxes_count', 'equals', ['number' => 0]))
        ->assertCanSeeTableRecords([$empty])
        ->assertCanNotSeeTableRecords([$one, $three]);

    Livewire::test(ListLocations::class)
        ->filterTable('queryBuilder', lre_rule('boxes_count', 'isMin', ['number' => 1]))
        ->assertCanSeeTableRecords([$one, $three])
        ->assertCanNotSeeTableRecords([$empty]);

    Livewire::test(ListLocations::class)
        ->filterTable('queryBuilder', lre_rule('boxes_count', 'isMax', ['number' => 1]))
        ->assertCanSeeTableRecords([$empty, $one])
        ->assertCanNotSeeTableRecords([$three]);

    Livewire::test(ListLocations::class)
        ->filterTable('queryBuilder', lre_rule('boxes_count', 'equals.inverse', ['number' => 3]))
        ->assertCanSeeTableRecords([$empty, $one])
        ->assertCanNotSeeTableRecords([$three]);

    Livewire::test(ListLocations::class)
        ->filterTable('queryBuilder', lre_rule('documents_count', 'isMin', ['number' => 2]))
        ->assertCanSeeTableRecords([$empty])
        ->assertCanNotSeeTableRecords([$one, $three]);
});

it('filters locations by name, type, repo (GLOBAL = none), identifier and active', function (): void {
    $room = qf_location($this->repo->id, ['name' => 'Mould Room', 'code' => 'NRA-1', 'type' => 'room']);
    $shelf = qf_location($this->repo->id, ['name' => 'Shelf A', 'code' => 'NRA-2', 'type' => 'museum', 'is_active' => false]);
    $global = qf_location(null, ['name' => 'Offsite', 'type' => 'room']);

    Livewire::test(ListLocations::class)
        ->filterTable('queryBuilder', lre_rule('name', 'equals', ['text' => 'Mould Room']))
        ->assertCanSeeTableRecords([$room])
        ->assertCanNotSeeTableRecords([$shelf, $global]);

    Livewire::test(ListLocations::class)
        ->filterTable('queryBuilder', lre_rule('type', 'is', ['values' => ['museum']]))
        ->assertCanSeeTableRecords([$shelf])
        ->assertCanNotSeeTableRecords([$room, $global]);

    Livewire::test(ListLocations::class)
        ->filterTable('queryBuilder', lre_rule('repository', 'isEmpty'))
        ->assertCanSeeTableRecords([$global])
        ->assertCanNotSeeTableRecords([$room, $shelf]);

    Livewire::test(ListLocations::class)
        ->filterTable('queryBuilder', lre_rule('code', 'equals', ['text' => 'NRA-2']))
        ->assertCanSeeTableRecords([$shelf])
        ->assertCanNotSeeTableRecords([$room, $global]);

    Livewire::test(ListLocations::class)
        ->filterTable('queryBuilder', lre_rule('is_active', 'isTrue.inverse'))
        ->assertCanSeeTableRecords([$shelf])
        ->assertCanNotSeeTableRecords([$room, $global]);
});

it('filters by the Inputter the column names: first "created" audit, else first "updated" one', function (): void {
    config(['audit.console' => true]);
    $alice = tap(qf_admin($this->repo->id))->update(['name' => 'Alice']);
    $bob = tap(qf_admin($this->repo->id))->update(['name' => 'Bob']);

    $this->actingAs($alice);
    $byAlice = qf_location($this->repo->id, ['name' => 'By Alice']);
    $this->actingAs($bob);
    $byBob = qf_location($this->repo->id, ['name' => 'By Bob']);
    // Bob edits Alice's location: she stays its inputter.
    $byAlice->update(['notes' => 'edited by Bob']);
    // Imported before auditing: no "created" audit, Alice's update counts.
    $imported = Location::withoutEvents(fn (): Location => qf_location($this->repo->id, ['name' => 'Imported']));
    $this->actingAs($alice);
    Location::withoutGlobalScopes()->find($imported->id)->update(['notes' => 'touched by Alice']);
    $this->actingAs($this->user);

    Livewire::test(ListLocations::class)
        ->filterTable('queryBuilder', lre_rule('inputter', 'is', ['values' => [$alice->id]]))
        ->assertCanSeeTableRecords([$byAlice, $imported])
        ->assertCanNotSeeTableRecords([$byBob])
        ->assertTableColumnStateSet('inputter', 'Alice', $imported);

    Livewire::test(ListLocations::class)
        ->filterTable('queryBuilder', lre_rule('inputter', 'is.inverse', ['values' => [$alice->id]]))
        ->assertCanSeeTableRecords([$byBob])
        ->assertCanNotSeeTableRecords([$byAlice, $imported]);
});

/* ------------------------------------------------------------------ *
 |  Other lists: rules that are not a plain column                     |
 * ------------------------------------------------------------------ */

it('filters subseries by hierarchy (the subseries and those under it) and by parent', function (): void {
    $reg = Series::create(['code' => 'RG1', 'title' => 'Registers', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $rwl = Series::create(['code' => 'RWL', 'title' => 'Wills', 'is_active' => true, 'repository_id' => $this->repo->id, 'parent_id' => $reg->id]);
    $deep = Series::create(['code' => 'RWX', 'title' => 'Deep', 'is_active' => true, 'repository_id' => $this->repo->id, 'parent_id' => $rwl->id]);
    $other = Series::create(['code' => 'OTH', 'title' => 'Other', 'is_active' => true, 'repository_id' => $this->repo->id]);

    Livewire::test(ListSeries::class)
        ->filterTable('queryBuilder', lre_rule('hierarchy', 'is', ['values' => [$reg->id]]))
        ->assertCanSeeTableRecords([$reg, $rwl, $deep])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::test(ListSeries::class)
        ->filterTable('queryBuilder', lre_rule('parent', 'isEmpty'))
        ->assertCanSeeTableRecords([$reg, $other])
        ->assertCanNotSeeTableRecords([$rwl, $deep]);
});

it('opens the flags list on the open flags, and the rule can be removed', function (): void {
    $doc = Document::factory()->create(['repository_id' => $this->repo->id, 'series_id' => $this->series->id]);
    $open = DocumentFlag::factory()->create(['document_id' => $doc->id, 'repository_id' => $this->repo->id, 'status' => 'open']);
    $ack = DocumentFlag::factory()->create(['document_id' => $doc->id, 'repository_id' => $this->repo->id, 'status' => 'acknowledged']);
    $resolved = DocumentFlag::factory()->create(['document_id' => $doc->id, 'repository_id' => $this->repo->id, 'status' => 'resolved']);

    Livewire::test(ListDocumentFlags::class)
        ->assertCanSeeTableRecords([$open, $ack])
        ->assertCanNotSeeTableRecords([$resolved])
        ->filterTable('queryBuilder', ['match' => 'all', 'rules' => []])
        ->assertCanSeeTableRecords([$open, $ack, $resolved]);
});

it('keeps the documents without any location under "Location is not"', function (): void {
    $mould = qf_location($this->repo->id, ['name' => 'Mould Room']);
    $inMould = Document::factory()->create(['repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'location_id' => $mould->id]);
    $nowhere = Document::factory()->create(['repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'location_id' => null]);

    Livewire::test(ListDocuments::class)
        ->filterTable('queryBuilder', lre_rule('location', 'is.inverse', ['values' => [$mould->id]]))
        ->assertCanSeeTableRecords([$nowhere])
        ->assertCanNotSeeTableRecords([$inMould]);
});

it('finds the audits that changed a field', function (): void {
    config(['audit.console' => true]);
    $mould = qf_location($this->repo->id, ['name' => 'Mould Room']);
    $mould->update(['code' => 'NRA-9']);
    $mould->update(['notes' => 'checked']);

    $page = Livewire::test(ListAudits::class)
        ->filterTable('queryBuilder', lre_rule('changed_field', 'equals', ['text' => 'code']));

    $ids = $page->instance()->getTableRecords()->pluck('id')->all();
    $codeAudit = Audit::query()->where('event', 'updated')->where('new_values', 'like', '%"code"%')->value('id');
    $notesAudit = Audit::query()->where('event', 'updated')->where('new_values', 'like', '%"notes"%')->value('id');

    expect($ids)->toContain($codeAudit)->not->toContain($notesAudit);
});
