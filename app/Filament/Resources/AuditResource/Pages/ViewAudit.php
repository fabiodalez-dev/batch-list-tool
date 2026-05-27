<?php

namespace App\Filament\Resources\AuditResource\Pages;

use App\Filament\Resources\AuditResource;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Series;
use App\Models\Volume;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use OwenIt\Auditing\Models\Audit;

class ViewAudit extends ViewRecord
{
    protected static string $resource = AuditResource::class;

    public function infolist(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic entries on ['default' => 1, 'md' => 2]; non-atomic content
        // (URL, KeyValue diffs) → columnSpanFull. The "auditable" target
        // record gets a link when we can resolve a Filament Resource view
        // for its class.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Context')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('When')
                            ->dateTime('Y-m-d H:i:s'),
                        TextEntry::make('event')
                            ->label('Event')
                            ->badge(),
                        TextEntry::make('user.name')
                            ->label('Who')
                            ->default('— (system)'),
                        TextEntry::make('user.email')
                            ->label('Email')
                            ->default('—'),
                        TextEntry::make('auditable_type')
                            ->label('Model')
                            ->formatStateUsing(fn (?string $state): string => $state
                                ? class_basename($state)
                                : '—'),
                        TextEntry::make('auditable_id')
                            ->label('Record ID')
                            ->url(function (?Audit $record): ?string {
                                if ($record === null
                                    || empty($record->auditable_type)
                                    || empty($record->auditable_id)) {
                                    return null;
                                }
                                $routeName = self::auditableRouteName($record->auditable_type);
                                if ($routeName === null) {
                                    return null;
                                }

                                return route($routeName, ['record' => $record->auditable_id]);
                            })
                            ->openUrlInNewTab(false),
                        TextEntry::make('ip_address')
                            ->label('IP'),
                        TextEntry::make('user_agent')
                            ->label('User-Agent'),
                        TextEntry::make('url')
                            ->label('URL')
                            ->columnSpanFull(),
                    ]),

                Section::make('Old values')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        KeyValueEntry::make('old_values')
                            ->hiddenLabel()
                            ->keyLabel('Field')
                            ->valueLabel('Old value')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => ! empty($record->old_values)),

                Section::make('New values')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        KeyValueEntry::make('new_values')
                            ->hiddenLabel()
                            ->keyLabel('Field')
                            ->valueLabel('New value')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => ! empty($record->new_values)),
            ]);
    }

    /**
     * Map an auditable_type FQCN to the corresponding Filament view route
     * name when one exists. Returns null when the model is not surfaced
     * through a Filament Resource (or the Resource has no view page).
     */
    private static function auditableRouteName(string $auditableType): ?string
    {
        return match (ltrim($auditableType, '\\')) {
            Document::class => 'filament.admin.resources.documents.view',
            Batch::class => 'filament.admin.resources.batches.view',
            Box::class => 'filament.admin.resources.boxes.view',
            BoxMovement::class => 'filament.admin.resources.box-movements.view',
            Authority::class => 'filament.admin.resources.authorities.view',
            Series::class => 'filament.admin.resources.series.view',
            Accession::class => 'filament.admin.resources.accessions.view',
            Location::class => 'filament.admin.resources.locations.view',
            Repository::class => 'filament.admin.resources.repositories.view',
            Volume::class => 'filament.admin.resources.volumes.view',
            DocumentFlag::class => 'filament.admin.resources.document-flags.view',
            default => null,
        };
    }
}
