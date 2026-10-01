<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\RelationManagers;

use App\Filament\Tables\HistoryTimelineTable;
use App\Models\Document;
use App\Support\History\Timeline;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The "History" tab of a document: one chronology of everything recorded about
 * it — audit trail, barcodes, seals, locations, box moves, identifiers, flags
 * (RFQ §3.1.5–§3.1.8). See Timeline and HistoryTimelineTable.
 *
 * Anchored on the audits relation, which every audited record has; the rows
 * themselves are built by Timeline from all the logs, not only the audits.
 */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'audits';

    protected static ?string $title = 'History';

    public function table(Table $table): Table
    {
        return HistoryTimelineTable::table(
            $table,
            fn (): Model => $this->getOwnerRecord(),
            fn (Model $owner) => $owner instanceof Document ? Timeline::forDocument($owner) : collect(),
            'document',
        );
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view_any_audit') ?? false;
    }
}
