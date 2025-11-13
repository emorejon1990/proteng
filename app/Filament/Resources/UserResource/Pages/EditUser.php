<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        $role = $this->data['role'] ?? null;

        if ($role) {
            // Quitar roles previos y asignar el nuevo
            $this->record->syncRoles([$role]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        // 🔥 Después de guardar, vuelve al listado
        return $this->getResource()::getUrl('index');
    }
}
