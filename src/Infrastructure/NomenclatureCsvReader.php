<?php

declare(strict_types=1);

namespace MarketplaceSync\Infrastructure;

use MarketplaceSync\Support\Logger;
use MarketplaceSync\Support\Money;

final class NomenclatureCsvReader
{
    public function __construct(private Logger $logger) {}

    /** @return array<string, list<array{code:string,sku:string,name:string,unit:string,priceCents:int,stock:float,line:int}>> */
    public function read(string $fileName): array
    {
        $stream = fopen($fileName, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open nomenclature CSV: {$fileName}");
        }

        $header = fgetcsv($stream, 0, ';');
        if (is_array($header) && isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        $expected = ['Код', 'Артикул', 'Наименование', 'ЕдИзм', 'Цена', 'Остаток'];
        if ($header !== $expected) {
            throw new \RuntimeException('Unexpected nomenclature CSV header.');
        }

        $result = [];
        $line = 1;
        while (($row = fgetcsv($stream, 0, ';')) !== false) {
            ++$line;
            if ($row === [null] || $row === []) {
                continue;
            }
            if (count($row) !== 6) {
                $this->logger->warning('Skipped malformed nomenclature row.', ['line' => $line]);
                continue;
            }

            [$code, $sku, $name, $unit, $price, $stock] = array_map('trim', $row);
            $sku = strtoupper($sku);
            $priceCents = Money::toCents($price);
            $stockValue = $this->parseOneCDecimal($stock);
            if ($code === '' || $sku === '' || $name === '' || $priceCents === null || $stockValue === null || $stockValue < 0) {
                $this->logger->warning('Skipped invalid nomenclature row.', ['line' => $line, 'sku' => $sku]);
                continue;
            }

            $result[$sku][] = [
                'code' => $code, 'sku' => $sku, 'name' => $name, 'unit' => $unit,
                'priceCents' => $priceCents, 'stock' => $stockValue, 'line' => $line,
            ];
        }
        fclose($stream);

        foreach ($result as $sku => $records) {
            if (count($records) > 1) {
                $this->logger->warning('Duplicate SKU in nomenclature.', ['sku' => $sku, 'lines' => array_column($records, 'line')]);
            }
        }

        return $result;
    }

    private function parseOneCDecimal(string $value): ?float
    {
        $normalized = str_replace(["\xC2\xA0", ' '], '', trim($value));
        $normalized = str_replace(',', '.', $normalized);
        return preg_match('/^-?\d+(?:\.\d+)?$/', $normalized) ? (float) $normalized : null;
    }
}
