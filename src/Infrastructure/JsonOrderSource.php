<?php

declare(strict_types=1);

namespace MarketplaceSync\Infrastructure;

use MarketplaceSync\Support\Logger;

/** File adapter. Replace this class with an HTTP adapter without affecting reconciliation. */
final class JsonOrderSource
{
    public function __construct(private Logger $logger) {}

    /** @return list<array<string, mixed>> */
    public function fetch(string $fileName): array
    {
        $json = file_get_contents($fileName);
        if ($json === false) {
            throw new \RuntimeException("Cannot read orders JSON: {$fileName}");
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Orders JSON is invalid: ' . $exception->getMessage(), previous: $exception);
        }
        $orders = $payload['data']['orders'] ?? null;
        if (!is_array($orders)) {
            throw new \RuntimeException('Orders JSON does not contain data.orders array.');
        }

        $valid = [];
        foreach ($orders as $index => $order) {
            if (!is_array($order)) {
                $this->logger->warning('Skipped non-object order.', ['index' => $index]);
                continue;
            }
            $valid[] = $order;
        }
        return $valid;
    }
}
