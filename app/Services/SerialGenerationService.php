<?php

namespace App\Services;

use App\Http\Controllers\SerialController;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;

class SerialGenerationService
{
    public function generate(mixed $quantity, ?User $actor): Collection
    {
        abort_unless($actor?->hasRole('Admin') && ! $actor->hasRole('Customer'), 403);
        $data = Validator::make(['quantity' => $quantity], ['quantity' => ['required', 'integer', 'min:1', 'max:1000']])->validate();

        return SerialController::generatepool((int) $data['quantity']);
    }
}
