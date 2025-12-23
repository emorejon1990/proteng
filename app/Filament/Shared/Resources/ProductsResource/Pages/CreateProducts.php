<?php

namespace App\Filament\Shared\Resources\ProductsResource\Pages;

use App\Filament\Shared\Resources\ProductsResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateProducts extends CreateRecord
{
    protected static string $resource = ProductsResource::class;
}
