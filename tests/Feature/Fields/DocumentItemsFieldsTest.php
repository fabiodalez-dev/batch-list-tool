<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\RelationManagers\ItemsRelationManager;
use App\Support\BoxItemisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Fields touched by the NAF document — box itemisation: document_items.position,
 * reference, description; Document::items(); BoxItemisation service; the Items
 * relation manager. Uses the reusable qf_* builders.
 */
uses(RefreshDatabase::class);

it('itemises a document into N sequential placeholder items', function (): void {
    $doc = qf_doc();
    $created = BoxItemisation::itemiseCount($doc, 71, 'Folder');

    expect($created)->toBe(71)
        ->and($doc->items()->count())->toBe(71)
        ->and($doc->items()->min('position'))->toBe(1)
        ->and($doc->items()->max('position'))->toBe(71);
});

it('names placeholder items with the given prefix', function (): void {
    $doc = qf_doc();
    BoxItemisation::itemiseCount($doc, 2, 'Volume');

    expect($doc->items()->orderBy('position')->pluck('reference')->all())->toBe(['Volume 1', 'Volume 2']);
});

it('itemises from a pasted list, one item per line', function (): void {
    $doc = qf_doc();
    $created = BoxItemisation::itemiseFromLines($doc, ['F-1', 'F-2', 'F-3']);

    expect($created)->toBe(3)
        ->and($doc->items()->orderBy('position')->pluck('reference')->all())->toBe(['F-1', 'F-2', 'F-3']);
});

it('splits reference and description on " | " or a tab', function (): void {
    $doc = qf_doc();
    BoxItemisation::itemiseFromLines($doc, ['F-1 | Deed of sale', "F-2\tWill"]);

    $items = $doc->items()->orderBy('position')->get();
    expect($items[0]->reference)->toBe('F-1')
        ->and($items[0]->description)->toBe('Deed of sale')
        ->and($items[1]->reference)->toBe('F-2')
        ->and($items[1]->description)->toBe('Will');
});

it('itemises from a real Excel sheet with reference and description columns', function (): void {
    $dir = storage_path('framework/testing');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $path = $dir . '/itemisation_' . uniqid() . '.xlsx';

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'Reference');
    $sheet->setCellValue('B1', 'Description');
    $sheet->setCellValue('A2', 'F-1');
    $sheet->setCellValue('B2', 'Deed of sale');
    $sheet->setCellValue('A3', 'F-2');
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    try {
        $doc = qf_doc();
        $lines = BoxItemisation::linesFromSpreadsheet($path);
        $created = BoxItemisation::itemiseFromLines($doc, $lines);

        $items = $doc->items()->orderBy('position')->get();
        expect($lines)->toBe(['F-1 | Deed of sale', 'F-2'])
            ->and($created)->toBe(2)
            ->and($items[0]->reference)->toBe('F-1')
            ->and($items[0]->description)->toBe('Deed of sale')
            ->and($items[1]->reference)->toBe('F-2');
    } finally {
        @unlink($path);
    }
});

it('skips blank lines when itemising from a list', function (): void {
    $doc = qf_doc();
    $created = BoxItemisation::itemiseFromLines($doc, ['A', '   ', '', 'B']);

    expect($created)->toBe(2)
        ->and($doc->items()->count())->toBe(2);
});

it('appends by default, continuing the position sequence', function (): void {
    $doc = qf_doc();
    BoxItemisation::itemiseCount($doc, 2);
    BoxItemisation::itemiseCount($doc, 2);

    expect($doc->items()->count())->toBe(4)
        ->and($doc->items()->max('position'))->toBe(4);
});

it('replaces the existing itemised list when asked, resetting positions', function (): void {
    $doc = qf_doc();
    BoxItemisation::itemiseCount($doc, 5);
    BoxItemisation::itemiseFromLines($doc, ['only-one'], replace: true);

    expect($doc->items()->count())->toBe(1)
        ->and($doc->items()->first()->position)->toBe(1)
        ->and($doc->items()->first()->reference)->toBe('only-one');
});

it('creates nothing for a non-positive count or all-blank lines', function (): void {
    $doc = qf_doc();

    expect(BoxItemisation::itemiseCount($doc, 0))->toBe(0)
        ->and(BoxItemisation::itemiseFromLines($doc, ['', ' ']))->toBe(0)
        ->and($doc->items()->count())->toBe(0);
});

it('cascades document_items when the parent document is force-deleted', function (): void {
    $doc = qf_doc();
    BoxItemisation::itemiseCount($doc, 3);
    $doc->forceDelete();

    expect(DB::table('document_items')->where('document_id', $doc->id)->count())->toBe(0);
});

it('itemises via the relation-manager Itemise action', function (): void {
    $this->actingAs(qf_admin());
    $doc = qf_doc();

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $doc,
        'pageClass' => EditDocument::class,
    ])
        ->callTableAction('itemise', data: ['count' => 4, 'prefix' => 'Folder'])
        ->assertHasNoTableActionErrors();

    expect($doc->items()->count())->toBe(4);
});
