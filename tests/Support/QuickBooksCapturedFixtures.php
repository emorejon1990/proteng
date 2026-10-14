<?php

use App\Services\QuickBooksReadGateway;

function capturedInvoice(string $id, string $format): array
{
    $fixtures = json_decode(file_get_contents(__DIR__.'/../Fixtures/QuickBooks/invoices-'.$format.'.sanitized.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($format === 'raw') {
        return $fixtures[$id];
    }

    return QuickBooksReadGateway::payload(restoreCapturedSdkValue($fixtures[$id]));
}

function restoreCapturedSdkValue(array $node): mixed
{
    if ($node['type'] === 'object') {
        $class = $node['class'];
        if (! str_starts_with($class, 'QuickBooksOnline\\API\\Data\\') || ! class_exists($class)) {
            throw new RuntimeException('Unsupported fixture class.');
        }
        $object = new $class;
        foreach ($node['properties'] as $name => $value) {
            if (! property_exists($object, $name)) {
                throw new RuntimeException('Unsupported fixture property.');
            }
            $object->$name = restoreCapturedSdkValue($value);
        }

        return $object;
    }
    if ($node['type'] === 'array') {
        $array = [];
        foreach ($node['entries'] as $entry) {
            $array[restoreCapturedSdkValue($entry['key'])] = restoreCapturedSdkValue($entry['value']);
        }

        return $array;
    }

    return $node['value'];
}
