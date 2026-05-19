# Utopia Billing

[![Build Status](https://travis-ci.org/utopia-php/billing.svg?branch=main)](https://travis-ci.com/utopia-php/billing)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/billing.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Billing is a simple and lightweight library for subscription management, invoicing, coupons, discounts, wallets, and unified transactions. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework), it can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/billing
```

## Usage

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Utopia\Billing\Billing;
use Utopia\Billing\Adapter\Database as DatabaseAdapter;
use Utopia\Database\Database;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\None as NoCache;

// Set up your database connection
$cache = new Cache(new NoCache());
$database = new Database($dbAdapter, $cache);
$database->setDatabase('my-database');
$database->setNamespace('my-namespace');

// Create billing instance with Database adapter
$billing = new Billing(new DatabaseAdapter($database));
$billing->setup(); // Creates all required collections

// Create a subscription
$subscription = $billing->createSubscription(
    entityId: 'org-123',
    planId: 'plan-pro',
);

// Activate after first payment succeeds
$billing->recordPaymentSuccess($subscription->getId());

// Create and finalize an invoice
$invoice = $billing->createInvoice(
    entityId: 'org-123',
    type: 'subscription',
    subscriptionId: $subscription->getId(),
    items: [
        ['type' => 'plan', 'description' => 'Pro Plan - April 2026', 'amount' => 15.00],
        ['type' => 'usage', 'description' => 'Bandwidth (50 GB)', 'amount' => 4.00, 'resource' => 'bandwidth'],
    ],
);

$finalized = $billing->finalizeInvoice($invoice->getId(), [
    'taxRate' => 17.0,
    'taxDescription' => 'VAT',
]);

// Mark as paid
$billing->markInvoicePaid($invoice->getId(), 'pi_stripe_123');
```

### Coupons & Discounts

```php
// Create a coupon
$coupon = $billing->createCoupon(
    code: 'SAVE20',
    type: 'percentage',
    value: 20.0,
    duration: 'repeating',
    durationInCycles: 3,
);

// Apply to a subscription — discounts are auto-applied during finalizeInvoice()
$discount = $billing->applyDiscount('SAVE20', $subscription->getId(), 'org-123');
```

### Wallet

```php
// Add funds
$topupInvoice = $billing->createInvoice('org-123', 'wallet_topup');
$billing->addFunds('org-123', $topupInvoice->getId(), 100.00);

// Deduct for payment
$billing->deductFunds('org-123', $invoice->getId(), 15.00);

// Check balance
$balance = $billing->getWalletBalance('org-123'); // 85.00
```

### Events

```php
$billing->on('subscription.created', function ($subscription) {
    // Send welcome email
});

$billing->on('invoice.finalized', function ($invoice) {
    // Trigger payment
});

$billing->on('subscription.budget_reached', function ($subscription) {
    // Restrict API access
});
```

### Invoice Rendering

```php
use Utopia\Billing\Render\HTML;

$renderer = new HTML();
$html = $renderer->render($invoice, [
    'entity' => ['name' => 'Acme Corp', 'email' => 'billing@acme.com'],
    'issuer' => ['name' => 'Utopia Inc', 'taxId' => 'US-12345'],
]);
```

## System Requirements

Utopia Billing requires PHP 8.1 or later. We recommend using the latest PHP version whenever possible.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

Fork the project, create a feature branch, and send us a pull request.

### Testing

Unit tests (no database required):
```bash
composer test
```

E2E tests (requires Docker):
```bash
docker compose up -d
docker compose exec tests vendor/bin/phpunit --testsuite E2E --group e2e
docker compose down
```

Static analysis and code style:
```bash
composer check   # PHPStan level max
composer lint    # Pint code style check
composer format  # Pint auto-fix
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
