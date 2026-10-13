<?php
/*
 * This file is part of the PayPal Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The "View payment" link on the admin order screen.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\DashboardLinkGateway;
use mindstellar\paypal\PayPalGateway;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$sandbox = fake_gateway($http, $orders);
$live    = fake_gateway($http, $orders, array('mode' => 'live'));
$meta    = static fn (string $mode): array => array(PayPalGateway::META_ORDER => '5O190127TN3999999', PayPalGateway::META_MODE => $mode);

harness_section('dashboard link');

check('the gateway links to the PayPal activity page', $sandbox instanceof DashboardLinkGateway);

$paid = $orders->add(9_990_000, 'USD', '3C679366HH9000001', 'paid', 'paypal', '2026-01-01 00:00:00', $meta('sandbox'));
pin('a sandbox payment', 'https://www.sandbox.paypal.com/activity/payment/3C679366HH9000001', $sandbox->dashboardUrl($paid));
pin('keeps its sandbox link after a switch to live', 'https://www.sandbox.paypal.com/activity/payment/3C679366HH9000001', $live->dashboardUrl($paid));
$livePaid = $orders->add(9_990_000, 'USD', '3C679366HH9000002', 'paid', 'paypal', '2026-01-01 00:00:00', $meta('live'));
pin('a live payment', 'https://www.paypal.com/activity/payment/3C679366HH9000002', $sandbox->dashboardUrl($livePaid));
$noMode = $orders->add(9_990_000, 'USD', '3C679366HH9000003', 'refunded');
pin('without a stored mode, the current one; refunded orders link too', 'https://www.paypal.com/activity/payment/3C679366HH9000003', $live->dashboardUrl($noMode));

pin('a pending order has none yet', null, $sandbox->dashboardUrl($orders->sent()));
pin('nor a failed one', null, $sandbox->dashboardUrl($orders->add(9_990_000, 'USD', '3C679366HH9000004', 'failed')));
pin('nor a paid one still holding the PayPal order id', null, $sandbox->dashboardUrl($orders->add(9_990_000, 'USD', '5O190127TN3999999', 'paid', 'paypal', '2026-01-01 00:00:00', $meta('sandbox'))));
pin('no ref, no link', null, $sandbox->dashboardUrl($orders->add(9_990_000, 'USD', null, 'paid')));
foreach (array('../../account', '3C67?x=1', 'abc', 'javascript:alert(1)', "3C679366HH\n9000001", '3c679366hh9000001') as $bad) {
    pin('a bad ref gives no link: ' . json_encode($bad), null, $sandbox->dashboardUrl($orders->add(9_990_000, 'USD', $bad, 'paid')));
}

exit(harness_result());
