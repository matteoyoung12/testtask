<?php

declare(strict_types=1);

namespace MarketplaceSync\Reporting;

final class CsvReportWriter
{
    /** @param list<array<string, scalar>> $rows @param array<string, int> $summary */
    public function write(string $fileName, array $rows, array $summary): void
    {
        $directory = dirname($fileName);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create report directory: {$directory}");
        }
        $stream = fopen($fileName, 'wb');
        if ($stream === false) throw new \RuntimeException("Cannot write report: {$fileName}");

        fwrite($stream, "\xEF\xBB\xBF"); // Excel-friendly UTF-8 BOM
        fputcsv($stream, ['order_id', 'created_at', 'status', 'line', 'sku', 'title', 'quantity', 'order_price', 'price_1c', 'stock_1c', 'issues', 'action'], ';');
        foreach ($rows as $row) fputcsv($stream, $row, ';');
        fputcsv($stream, [] , ';');
        fputcsv($stream, ['summary_metric', 'value'], ';');
        foreach ($summary as $key => $value) fputcsv($stream, [$key, $value], ';');
        fclose($stream);
    }
}
