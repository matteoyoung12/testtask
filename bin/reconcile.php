<?php

declare(strict_types=1);

use MarketplaceSync\Application\ReconciliationService;
use MarketplaceSync\Infrastructure\JsonOrderSource;
use MarketplaceSync\Infrastructure\NomenclatureCsvReader;
use MarketplaceSync\Reporting\CsvReportWriter;
use MarketplaceSync\Support\Logger;

require dirname(__DIR__) . '/bootstrap.php';

function usage(): void
{
    fwrite(STDERR, "Usage: php bin/reconcile.php <orders.json> <nomenclature_1c.csv> [report.csv] [app.log]\n");
}

if ($argc < 3 || $argc > 5) {
    usage();
    exit(64);
}

$root = dirname(__DIR__);
$reportFile = $argv[3] ?? $root . '/output/reconciliation_report.csv';
$logFile = $argv[4] ?? $root . '/output/reconciliation.log';

try {
    $logger = new Logger($logFile);
    $orders = (new JsonOrderSource($logger))->fetch($argv[1]);
    $nomenclature = (new NomenclatureCsvReader($logger))->read($argv[2]);
    $result = (new ReconciliationService($logger))->reconcile($orders, $nomenclature);
    (new CsvReportWriter())->write($reportFile, $result['rows'], $result['summary']);

    echo "Report: {$reportFile}\nLog: {$logFile}\n";
    foreach ($result['summary'] as $metric => $value) {
        echo "{$metric}: {$value}\n";
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Fatal error: {$exception->getMessage()}\n");
    exit(1);
}
