<?php

namespace App\Filament\Resources\SeriesResource\Pages;

use App\Filament\Concerns\HandlesCustomFieldForm;
use App\Filament\Resources\SeriesResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSeries extends CreateRecord
{
    use HandlesCustomFieldForm;

    protected static string $resource = SeriesResource::class;
}
