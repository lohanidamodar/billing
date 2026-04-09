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
$database->setNamespace('my-namespace');

// Create billing instance with Database adapter
$billing = new Billing(new DatabaseAdapter($database));
$billing->setup(); // Creates all required collections

// Create a subscription
$subscription = $billing->createSubscription(
    customerId: 'customer-123',
    planId: 'plan-pro',
    status: 'active'
);

// Generate an invoice
$invoice = $billing->createInvoice(
    customerId: 'customer-123',
    subscriptionId: $subscription->getId(),
    lineItems: [
        ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 2999],
        ['type' => 'discount', 'description' => '20% off', 'amount' => -600],
    ]
);
```

## System Requirements

Utopia Billing requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

Fork the project, create a feature branch, and send us a pull request.

### Testing

```
vendor/bin/phpunit --configuration phpunit.xml
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
