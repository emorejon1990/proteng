<?php

namespace App\Filament\Admin\Resources\RolesResource\Pages;

use App\Filament\Admin\Resources\RolesResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateRoles extends CreateRecord
{
    protected static string $resource = RolesResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
