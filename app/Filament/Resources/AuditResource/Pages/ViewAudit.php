<?php

namespace App\Filament\Resources\AuditResource\Pages;

use App\Filament\Resources\AuditResource;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewAudit extends ViewRecord
{
    protected static string $resource = AuditResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Context')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')->label('When')->dateTime('Y-m-d H:i:s'),
                        TextEntry::make('event')->badge(),
                        TextEntry::make('user.name')->label('Who')->default('— (system)'),
                        TextEntry::make('user.email')->default('—'),
                        TextEntry::make('auditable_type')
                            ->label('Model')
                            ->formatStateUsing(fn (string $state): string => class_basename($state)),
                        TextEntry::make('auditable_id')->label('Record ID'),
                        TextEntry::make('ip_address')->label('IP'),
                        TextEntry::make('user_agent')->label('User-Agent'),
                        TextEntry::make('url')->label('URL')->columnSpanFull(),
                    ]),
                Section::make('Old values')
                    ->collapsible()
                    ->schema([
                        KeyValueEntry::make('old_values')
                            ->keyLabel('Field')
                            ->valueLabel('Old value'),
                    ])
                    ->visible(fn ($record) => ! empty($record->old_values)),
                Section::make('New values')
                    ->collapsible()
                    ->schema([
                        KeyValueEntry::make('new_values')
                            ->keyLabel('Field')
                            ->valueLabel('New value'),
                    ])
                    ->visible(fn ($record) => ! empty($record->new_values)),
            ]);
    }
}
