<?php

declare(strict_types=1);

namespace MarketplaceSync\Support;

/** Monetary amount in minor units; avoids floating-point comparison errors. */
final class Money
{
    public static function toCents(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }
        if (is_float($value)) {
            return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
        }
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim(str_replace(["\xC2\xA0", ' '], '', $value));
        $normalized = str_replace(',', '.', $normalized);
        if (!preg_match('/^-?(?:\d+)(?:\.\d{1,2})?$/', $normalized)) {
            return null;
        }

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    public static function format(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }
}
