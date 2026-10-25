<?php

declare(strict_types=1);

namespace MarketplaceSync\Application;

use MarketplaceSync\Support\Logger;
use MarketplaceSync\Support\Money;

final class ReconciliationService
{
    private const TRANSFERABLE_STATUSES = ['new', 'confirmed'];

    public function __construct(private Logger $logger) {}

    /**
     * @param list<array<string, mixed>> $orders
     * @param array<string, list<array{code:string,sku:string,name:string,unit:string,priceCents:int,stock:float,line:int}>> $nomenclature
     * @return array{rows:list<array<string, scalar>>, summary:array<string, int>}
     */
    public function reconcile(array $orders, array $nomenclature): array
    {
        $rows = [];
        $seenOrders = [];
        $reserved = [];
        $summary = [
            'orders_received' => count($orders), 'report_rows' => 0, 'duplicates' => 0,
            'cancelled' => 0, 'art_not_found' => 0, 'price_mismatch' => 0,
            'stock_shortage' => 0, 'nomenclature_duplicates' => 0, 'invalid_records' => 0,
        ];

        foreach ($orders as $position => $order) {
            $orderId = $this->text($order['id'] ?? null) ?: 'ROW-' . ($position + 1);
            $status = strtolower($this->text($order['status'] ?? null));
            $createdAt = $this->text($order['created_at'] ?? null);
            $items = $order['items'] ?? null;
            $orderIssues = [];

            if ($this->text($order['id'] ?? null) === '') {
                $orderIssues[] = 'INVALID_ORDER_ID';
                $this->logInvalidOrder($position, $orderId, 'missing id');
            }
            if (!is_array($items)) {
                $items = [];
                $orderIssues[] = 'INVALID_ITEMS';
                $this->logInvalidOrder($position, $orderId, 'items is not an array');
            }
            if ($items === []) {
                $orderIssues[] = 'EMPTY_ORDER';
            }

            $signature = hash('sha256', json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $isDuplicate = isset($seenOrders[$orderId]);
            $duplicateIssue = '';
            if ($isDuplicate) {
                $duplicateIssue = $seenOrders[$orderId] === $signature ? 'DUPLICATE_ORDER_EXACT' : 'DUPLICATE_ORDER_ID_CONFLICT';
                ++$summary['duplicates'];
                $this->logger->warning('Duplicate marketplace order ignored for reservation.', ['order_id' => $orderId, 'kind' => $duplicateIssue]);
            } else {
                $seenOrders[$orderId] = $signature;
            }

            $isCancelled = $status === 'cancelled';
            if ($isCancelled) {
                ++$summary['cancelled'];
            }
            if ($status === '' || (!$isCancelled && !in_array($status, self::TRANSFERABLE_STATUSES, true))) {
                $orderIssues[] = 'UNSUPPORTED_STATUS';
            }

            $lineCents = [];
            $hasInvalidLinePrice = false;
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    $hasInvalidLinePrice = true;
                    continue;
                }
                $price = Money::toCents($item['price'] ?? null);
                $quantity = $item['quantity'] ?? null;
                if ($price === null || !is_int($quantity) || $quantity <= 0) {
                    $hasInvalidLinePrice = true;
                    continue;
                }
                $lineCents[$index] = $price * $quantity;
            }
            $orderTotal = Money::toCents($order['total'] ?? null);
            if ($orderTotal === null) {
                $orderIssues[] = 'ORDER_TOTAL_MISSING_OR_INVALID';
                $this->logInvalidOrder($position, $orderId, 'total missing or invalid');
            } elseif (!$hasInvalidLinePrice && array_sum($lineCents) !== $orderTotal) {
                $orderIssues[] = 'ORDER_TOTAL_MISMATCH';
                $this->logger->warning('Order total differs from its lines.', ['order_id' => $orderId, 'declared_cents' => $orderTotal, 'calculated_cents' => array_sum($lineCents)]);
            }

            if ($items === []) {
                $rows[] = $this->makeRow($orderId, $createdAt, $status, 0, '', '', '', '', '', '', $this->combineIssues($orderIssues, $duplicateIssue, $isCancelled ? 'CANCELLED' : ''), $this->action($orderIssues, $duplicateIssue, $isCancelled, false));
                continue;
            }

            foreach ($items as $lineNumber => $item) {
                if (!is_array($item)) {
                    ++$summary['invalid_records'];
                    $this->logger->warning('Skipped malformed order item.', ['order_id' => $orderId, 'line' => $lineNumber + 1]);
                    $rows[] = $this->makeRow($orderId, $createdAt, $status, $lineNumber + 1, '', '', '', '', '', '', $this->combineIssues($orderIssues, $duplicateIssue, 'INVALID_ITEM'), 'MANUAL_CORRECTION');
                    continue;
                }

                $sku = strtoupper($this->text($item['sku'] ?? null));
                $title = $this->text($item['title'] ?? null);
                $quantity = $item['quantity'] ?? null;
                $priceCents = Money::toCents($item['price'] ?? null);
                $issues = $orderIssues;
                $price1c = null;
                $stock = null;
                $requestedAfter = null;

                if ($sku === '') {
                    $issues[] = 'INVALID_SKU';
                }
                if (!is_int($quantity) || $quantity <= 0) {
                    $issues[] = 'INVALID_QUANTITY';
                }
                if ($priceCents === null || $priceCents < 0) {
                    $issues[] = 'INVALID_PRICE';
                }

                $records = $sku === '' ? [] : ($nomenclature[$sku] ?? []);
                if ($sku !== '' && $records === []) {
                    $issues[] = 'ARTICLE_NOT_FOUND';
                    ++$summary['art_not_found'];
                } elseif (count($records) > 1) {
                    $issues[] = 'NOMENCLATURE_DUPLICATE';
                    ++$summary['nomenclature_duplicates'];
                } elseif (count($records) === 1) {
                    $record = $records[0];
                    $price1c = $record['priceCents'];
                    $stock = $record['stock'];
                    if ($priceCents !== null && $priceCents !== $price1c) {
                        $issues[] = 'PRICE_MISMATCH';
                        ++$summary['price_mismatch'];
                    }

                    // Reserve in source order only for an otherwise usable active line.
                    $canReserve = !$isDuplicate && !$isCancelled && in_array($status, self::TRANSFERABLE_STATUSES, true)
                        && is_int($quantity) && $quantity > 0 && $priceCents !== null && $priceCents >= 0
                        && !in_array('ORDER_TOTAL_MISSING_OR_INVALID', $issues, true)
                        && !in_array('ORDER_TOTAL_MISMATCH', $issues, true);
                    if ($canReserve) {
                        $before = $reserved[$sku] ?? 0.0;
                        $requestedAfter = $before + $quantity;
                        if ($requestedAfter > $stock + 0.000001) {
                            $issues[] = 'STOCK_SHORTAGE';
                            ++$summary['stock_shortage'];
                        }
                        $reserved[$sku] = $requestedAfter;
                    }
                }

                if (array_intersect($issues, ['INVALID_SKU', 'INVALID_QUANTITY', 'INVALID_PRICE', 'INVALID_ITEM']) !== []) {
                    ++$summary['invalid_records'];
                    $this->logger->warning('Invalid order item.', ['order_id' => $orderId, 'line' => $lineNumber + 1, 'issues' => $issues]);
                }
                $allIssues = $this->combineIssues($issues, $duplicateIssue, $isCancelled ? 'CANCELLED' : '');
                $rows[] = $this->makeRow(
                    $orderId, $createdAt, $status, $lineNumber + 1, $sku, $title, $quantity,
                    Money::format($priceCents), Money::format($price1c), $stock === null ? '' : (string) $stock,
                    $allIssues, $this->action($issues, $duplicateIssue, $isCancelled, $allIssues === '') . ($requestedAfter === null ? '' : " (reserved: {$requestedAfter})")
                );
            }
        }

        $summary['report_rows'] = count($rows);
        return ['rows' => $rows, 'summary' => $summary];
    }

    /** @return array<string, scalar> */
    private function makeRow(string $orderId, string $createdAt, string $status, int $line, string $sku, string $title, mixed $quantity, string $orderPrice, string $oneCPrice, string $stock, string $issues, string $action): array
    {
        return ['order_id' => $orderId, 'created_at' => $createdAt, 'status' => $status, 'line' => $line, 'sku' => $sku, 'title' => $title, 'quantity' => is_scalar($quantity) ? $quantity : '', 'order_price' => $orderPrice, 'price_1c' => $oneCPrice, 'stock_1c' => $stock, 'issues' => $issues, 'action' => $action];
    }

    private function combineIssues(array $issues, string ...$more): string
    {
        return implode('|', array_values(array_unique(array_filter([...$issues, ...$more]))));
    }

    private function action(array $issues, string $duplicateIssue, bool $isCancelled, bool $clean): string
    {
        if ($isCancelled) return 'DO_NOT_TRANSFER_CANCELLED';
        if ($duplicateIssue !== '') return 'DO_NOT_TRANSFER_DUPLICATE';
        if ($clean) return 'READY_TO_TRANSFER';
        if (in_array('STOCK_SHORTAGE', $issues, true)) return 'HOLD_REPLENISH_OR_PARTIAL_FULFILMENT';
        if (array_intersect($issues, ['ARTICLE_NOT_FOUND', 'NOMENCLATURE_DUPLICATE']) !== []) return 'HOLD_FIX_NOMENCLATURE';
        return 'HOLD_FIX_ORDER_DATA';
    }

    private function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
    }

    private function logInvalidOrder(int $position, string $orderId, string $reason): void
    {
        $this->logger->warning('Invalid order data.', ['position' => $position + 1, 'order_id' => $orderId, 'reason' => $reason]);
    }
}
