<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

trait PreservesTraceabilityRecords
{
    protected static function bootPreservesTraceabilityRecords(): void
    {
        static::deleting(function (Model $model) {
            throw new LogicException('Traceability records must be retained.');
        });
    }
}
