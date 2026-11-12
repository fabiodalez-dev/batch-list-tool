<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

/**
 * The condensed View / Edit layout of the record pages (client 2026-10-30):
 * one "Details" section holding the record's fields in a dense grid, and the
 * audit info apart, closed by default. The View and the Edit of a record use
 * the same sections in the same order, so a field is found in the same place
 * on both.
 */
final class CompactLayout
{
    public const string DETAILS = 'Details';

    public const string AUDIT = 'Audit info';

    /** One column on a phone, two on a tablet, four on a desktop. */
    public const array GRID = ['default' => 1, 'md' => 2, 'xl' => 4];

    /** Half the desktop grid, the whole of a smaller one. */
    public const array HALF = ['default' => 1, 'md' => 2];

    /**
     * The closed "Audit info" section of a View. The timestamps stay for the
     * super admin, as before; `$extra` entries (a subseries' own inputter and
     * date of creation) are record data and are shown to everyone, so the
     * section is visible whenever it holds any of them.
     *
     * @param array<Component> $extra
     */
    public static function auditInfolist(array $extra = []): Section
    {
        $isSuperAdmin = static fn (): bool => (bool) auth()->user()?->hasRole('super_admin');

        return Section::make(self::AUDIT)
            ->columns(self::GRID)
            ->collapsed()
            ->schema([
                ...$extra,
                TextEntry::make('created_at')->dateTime()->label('Created')->visible($isSuperAdmin),
                TextEntry::make('updated_at')->dateTime()->label('Updated')->visible($isSuperAdmin),
                TextEntry::make('deleted_at')->dateTime()->label('Trashed')->placeholder('—')->visible($isSuperAdmin),
            ])
            ->visible(fn (): bool => $extra !== [] || $isSuperAdmin());
    }
}
