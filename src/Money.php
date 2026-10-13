<?php

/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\paypal;

/**
 * Converts between core's micros (value x 1,000,000) and PayPal's decimal strings.
 */
final class Money
{
    /** Currencies PayPal charges in whole units: a decimal amount is an error. */
    public const ZERO_DECIMAL = array('HUF', 'JPY', 'TWD');

    /** Every currency the PayPal REST API takes payments in. */
    public const SUPPORTED = array(
        'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS',
        'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD',
    );

    /**
     * Whether PayPal takes payments in $currency.
     *
     * @param string $currency ISO 4217, any case
     *
     * @return bool
     */
    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::SUPPORTED, true);
    }

    /**
     * How many decimal places PayPal allows for $currency.
     *
     * @param string $currency
     *
     * @return int
     */
    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /**
     * Micros to PayPal's amount string, such as "9.99" or "1000", or null when PayPal
     * cannot charge that exact amount in that currency.
     *
     * @param int    $micros
     * @param string $currency
     *
     * @return string|null
     */
    public static function toValue(int $micros, string $currency): ?string
    {
        if ($micros < 0 || !self::supported($currency)) {
            return null;
        }
        $decimals = self::decimals($currency);
        $divisor  = 10 ** (6 - $decimals);
        if ($micros % $divisor !== 0) {
            return null;
        }
        $minor = intdiv($micros, $divisor);
        if ($decimals === 0) {
            return (string) $minor;
        }

        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * PayPal's amount string to micros, or null when it is not a plain positive decimal.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    public static function toMicros($value): ?int
    {
        if (!is_string($value) || !preg_match('/^(\d{1,12})(?:\.(\d{1,6}))?$/', $value, $m)) {
            return null;
        }

        return (int) $m[1] * 1_000_000 + (int) str_pad($m[2] ?? '', 6, '0');
    }

    /**
     * Micros and currency from a PayPal amount object, {currency_code, value}.
     *
     * @param mixed $amount
     *
     * @return array{0:int,1:string}|null
     */
    public static function fromAmount($amount): ?array
    {
        if (!is_array($amount) || !is_string($amount['currency_code'] ?? null)) {
            return null;
        }
        $micros = self::toMicros($amount['value'] ?? null);

        return $micros === null ? null : array($micros, strtoupper($amount['currency_code']));
    }
}
