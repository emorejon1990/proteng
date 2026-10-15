<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class InvoiceOperationalException extends ValidationException
{
    public function __construct(public readonly array $diagnostic)
    {
        $validation = ValidationException::withMessages(['invoice' => $diagnostic['message']]);
        parent::__construct($validation->validator);
    }

    public static function diagnostics(ValidationException $exception): array
    {
        return $exception instanceof self ? [$exception->diagnostic] : array_map(
            fn ($message) => ['code' => 'operational_validation_failed', 'rule' => 'invoice_requirements', 'message' => $message],
            array_merge(...array_values($exception->errors()))
        );
    }
}
