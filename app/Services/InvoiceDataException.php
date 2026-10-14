<?php

namespace App\Services;

class InvoiceDataException extends \RuntimeException
{
    public function __construct(string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
