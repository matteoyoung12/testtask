<?php

declare(strict_types=1);

use MarketplaceSync\Application\ReconciliationService;
use MarketplaceSync\Infrastructure\JsonOrderSource;
use MarketplaceSync\Infrastructure\NomenclatureCsvReader;
use MarketplaceSync\Support\Logger;

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/bugs/OrderTotal.php';

$log = sys_get_temp_dir() . '/marketplace-sync-smoke-' . getmypid() . '.log';
try {
    $logger = new Logger($log);
    $orders = (new JsonOrderSource($logger))->fetch(dirname(__DIR__) . '/data/orders.json');
    $catalogue = (new NomenclatureCsvReader($logger))->read(dirname(__DIR__) . '/data/nomenclature_1c.csv');
    $result = (new ReconciliationService($logger))->reconcile($orders, $catalogue);

    $expected = [
        'orders_received' => 31, 'report_rows' => 54, 'duplicates' => 1, 'cancelled' => 1,
        'art_not_found' => 2, 'price_mismatch' => 3, 'stock_shortage' => 4,
        'nomenclature_duplicates' => 1, 'invalid_records' => 1,
    ];
    if ($result['summary'] !== $expected) {
        throw new RuntimeException('Unexpected reconciliation summary: ' . json_encode($result['summary']));
    }
    $withDiscount = OrderTotal::calculate([
        ['sku' => 'A', 'qty' => 2, 'price' => 3.20],
        ['sku' => 'B', 'qty' => 1, 'price' => 18.50],
        ['sku' => 'C', 'qty' => 1, 'price' => 1890.00],
    ], 10);
    $expectedTotal = ['subtotal' => 1914.90, 'total' => 1723.41, 'vat' => 287.24];
    foreach ($expectedTotal as $key => $value) {
        if (abs($withDiscount[$key] - $value) > 0.00001) {
            throw new RuntimeException("OrderTotal regression check failed for {$key}.");
        }
    }
    echo "Smoke test passed\n";
} finally {
    unset($logger);
    if (is_file($log)) {
        unlink($log);
    }
}
