<?php

declare(strict_types=1);

namespace App\Filament\Resources\BoxResource\RelationManagers;

use App\Filament\Tables\LocationHistoryTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Location history" tab of a box: every location it has been moved
 * from and to (RFQ §3.1.6). Read-only; see LocationHistoryTable.
 */
class LocationHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'locationHistory';

    protected static ?string $title = 'Location history';

    public function form(Schema $schema): Schema
    {
        return LocationHistoryTable::form($schema);
    }

    public function table(Table $table): Table
    {
        return LocationHistoryTable::table($table);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
