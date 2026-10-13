<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Micros to PayPal's amount strings and back, for two- and zero-decimal currencies.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\paypal\Money;

harness_section('two decimals');
pin('9.99 USD is "9.99"', '9.99', Money::toValue(9_990_000, 'USD'));
pin('lower case works', '9.99', Money::toValue(9_990_000, 'usd'));
pin('whole amounts keep two decimals', '10.00', Money::toValue(10_000_000, 'EUR'));
pin('pence are padded', '0.05', Money::toValue(50_000, 'GBP'));
pin('zero is "0.00"', '0.00', Money::toValue(0, 'EUR'));
pin('a large amount', '123456.78', Money::toValue(123_456_780_000, 'USD'));
pin('a fraction of a cent cannot be charged', null, Money::toValue(9_995_000, 'EUR'));
pin('a negative amount is refused', null, Money::toValue(-1_000_000, 'EUR'));

harness_section('zero decimals (HUF, JPY, TWD)');
foreach (array('HUF', 'JPY', 'TWD') as $code) {
    pin($code . ' has none', 0, Money::decimals($code));
    pin($code . ': 1000 units is "1000"', '1000', Money::toValue(1_000_000_000, $code));
    pin($code . ': a fraction is refused', null, Money::toValue(10_450_000, $code));
}
pin('the zero-decimal list is exactly these', array('HUF', 'JPY', 'TWD'), Money::ZERO_DECIMAL);

harness_section('currencies PayPal does not take');
foreach (array('INR', 'KWD', 'ZAR', 'XYZ', 'RUB') as $code) {
    check($code . ' is not supported', !Money::supported($code));
    pin($code . ' gives no amount', null, Money::toValue(1_000_000, $code));
}
check('every listed code is three upper-case letters', array_filter(Money::SUPPORTED, static fn ($c) => !preg_match('/^[A-Z]{3}$/', $c)) === array());
check('every zero-decimal code is supported', array_diff(Money::ZERO_DECIMAL, Money::SUPPORTED) === array());
pin('24 currencies', 24, count(Money::SUPPORTED));

harness_section('PayPal amounts back to micros');
pin('"9.99" is 9.99 in micros', 9_990_000, Money::toMicros('9.99'));
pin('"10" is 10', 10_000_000, Money::toMicros('10'));
pin('"0.5" is half', 500_000, Money::toMicros('0.5'));
pin('"1000" yen', 1_000_000_000, Money::toMicros('1000'));
foreach (array('-1.00', '1e3', ' 1.00', '1.', '.5', '1,00', '1.0000000', '') as $bad) {
    pin('refused: ' . json_encode($bad), null, Money::toMicros($bad));
}
pin('a number is refused, only strings count', null, Money::toMicros(9.99));
pin('an amount object', array(9_990_000, 'USD'), Money::fromAmount(array('currency_code' => 'usd', 'value' => '9.99')));
pin('without a currency', null, Money::fromAmount(array('value' => '9.99')));
pin('not an array', null, Money::fromAmount('9.99'));

harness_section('round trip');
foreach (array(array(9_990_000, 'USD'), array(1_000_000_000, 'JPY'), array(250_000_000, 'HUF'), array(10_000, 'EUR')) as [$micros, $code]) {
    pin($code . ' ' . $micros, $micros, Money::toMicros(Money::toValue($micros, $code)));
}

exit(harness_result());
