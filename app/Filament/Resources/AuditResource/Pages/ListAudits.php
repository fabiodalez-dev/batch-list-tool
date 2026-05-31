<?php

namespace App\Filament\Resources\AuditResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\AuditResource;
use Filament\Resources\Pages\ListRecords;

class ListAudits extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = AuditResource::class;
}
