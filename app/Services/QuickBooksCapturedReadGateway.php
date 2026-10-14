<?php

namespace App\Services;

/** No network or token access: only responses captured for the selective test. */
class QuickBooksCapturedReadGateway extends QuickBooksReadGateway
{
    public function __construct(private string $capturedRealm, private array $responses) {}

    public function realm(): string
    {
        return $this->capturedRealm;
    }

    public function find(string $entity, string $id): array
    {
        self::entityRef($id, ['entity' => $entity]);
        if (! isset($this->responses[$entity][$id])) {
            throw new InvoiceDataException('Required reference was not captured for this selective test.', ['entity' => $entity]);
        }

        return $this->responses[$entity][$id];
    }

    public function page(string $entity, int $position, int $size = 100): array
    {
        throw new \LogicException('Global listings are prohibited in selective tests.');
    }
}
