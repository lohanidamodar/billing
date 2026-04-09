# CLAUDE.md - Utopia Billing Library

## Project Overview

`utopia-php/billing` is a generic billing library for subscription management, invoicing, coupons/discounts, wallets, and unified transactions. It is part of the Utopia Framework ecosystem but has no Appwrite-specific logic. Plans and product definitions stay config-driven in the consuming application.

## Architecture

### Pattern
Facade pattern wrapping an abstract `Adapter`. The main `Billing` class delegates all storage operations to the adapter.

- `Billing` (facade) -> `Adapter` (abstract) -> `Adapter\Database` (concrete, uses utopia-php/database)
- This follows the same pattern as `utopia-php/audit` and `utopia-php/abuse`.

### Dependencies
- `utopia-php/database` — persistence layer (collections, documents, queries)
- `utopia-php/pay` — payment gateway abstraction (Stripe, etc.), provides Credit and Discount calculation utilities

### Namespace
```
Utopia\Billing
```

PSR-4 autoloading: `Utopia\\Billing\\` -> `src/Billing/`

## Collections

The library uses 6 database collections, all namespace-isolated (no hardcoded prefix):

1. **subscriptions** — customer subscription records (plan, status, billing cycle, anchors)
2. **invoices** — invoices with denormalized line items stored inline (not a separate collection)
3. **coupons** — coupon templates (code, discount type, duration, constraints)
4. **discounts** — applied coupon instances with cycle countdown tracking
5. **wallets** — user-funded prepaid balance (one per customer/currency)
6. **transactions** — unified ledger of all money movements

## Key Models

### Subscription
Customer's subscription to a plan. Tracks status (active, past_due, canceled, trialing), current period start/end, billing anchor, and trial end.

### Invoice
Contains denormalized line items as an array attribute. Each line item has the same structure regardless of type.

**Everything is a line item**: plan charge, usage charge, addon, discount, tax, proration, refund — all use the same line item schema with a `type` field to distinguish them.

### Coupon (Template)
Defines a reusable coupon: code, discount type (percentage or fixed_amount), amount/percentage, duration model, currency, max redemptions, valid dates.

### Discount (Applied Instance)
When a coupon is applied to a customer/subscription, a Discount record is created. Tracks the coupon reference, the customer, subscription scope, and **cycles remaining** as a countdown.

**Discount duration model** (following the Lago pattern):
- `once` — applies to the next invoice only
- `repeating` — applies for N billing cycles (`cyclesRemaining` decrements each cycle)
- `forever` — applies indefinitely (`cyclesRemaining` is null)

**Discount scoping**: 
- `scope.resources` (array) — when set, discount applies only to specific line items (line-level discount)
- `scope.resources` is null — discount applies to the entire invoice (invoice-level discount)

### Wallet
User-funded prepaid balance. Each customer can have one wallet per currency. Fixed-amount coupons credit the wallet immediately upon application.

### Transaction
Unified ledger entry for all money movements. Every financial event produces a transaction record.

**Transaction types**: `gateway_charge`, `gateway_refund`, `wallet_topup`, `wallet_deduction`, `coupon_credit`, `credit_expiry`, `proration_credit`, `proration_debit`, `invoice_payment`, `credit_note`, etc.

Each transaction references its source (invoice, wallet, coupon, etc.) for full traceability.

### Period
Value object representing a billing period (start datetime, end datetime). Used by subscriptions and invoice line items.

### Credit (from Pay)
Calculation utility from `utopia-php/pay` for credit/proration math.

### Discount (from Pay)
Calculation utility from `utopia-php/pay` for applying percentage and fixed discounts.

## Invoice Details

### Numbering
Invoice numbers use a configurable format (e.g., `INV-{YEAR}-{SEQ}`). The format is set on the Billing facade or adapter.

### Rendering
- **HTML**: Built-in template rendering (templates stored in `templates/` directory)
- **PDF**: Optional, via abstract `Render/Renderer` class. Implement with mPDF or similar. Not included by default — listed as a composer suggestion.

### Credit Notes
Credit notes are modeled as invoices with `type = 'credit_note'` and a `referenceInvoiceId` pointing to the original invoice. They contain negative line items.

## Events

The library emits lifecycle events for key operations. Consuming applications can register listeners.

Key events:
- `subscription.created`, `subscription.updated`, `subscription.canceled`
- `invoice.created`, `invoice.finalized`, `invoice.paid`, `invoice.voided`
- `discount.applied`, `discount.exhausted` (when cyclesRemaining reaches 0)
- `wallet.credited`, `wallet.debited`
- `transaction.created`

## Coding Conventions

- Follow utopia-php coding standards
- PSR-4 autoloading with strict types (`declare(strict_types=1);`)
- PHP 8.0+ (use named arguments, union types, match expressions where appropriate)
- Test with PHPUnit (tests in `tests/` directory)
- Use `$db->getAuthorization()->skip()` for internal database operations (same pattern as audit)
- The `setup()` method on the adapter creates all 6 collections with proper attributes and indexes
- All public methods should have proper PHPDoc blocks
- Use value objects for complex types (Period, line items)

## Directory Structure

```
src/Billing/
    Billing.php              # Main facade class
    Adapter.php              # Abstract adapter
    Adapter/
        Database.php         # Database adapter implementation
    Model/
        Subscription.php
        Invoice.php
        Coupon.php
        Discount.php
        Wallet.php
        Transaction.php
        Period.php
        LineItem.php
    Render/
        Renderer.php         # Abstract renderer for invoice output
        HTML.php             # Built-in HTML renderer
    Event/
        Event.php            # Event base class or interface
tests/
    Billing/
        BillingTest.php
        Adapter/
            DatabaseTest.php
templates/
    invoice.html             # Default HTML invoice template
```

## Important Design Decisions

1. **Library is generic** — no Appwrite-specific logic. Plans, products, and pricing tiers are defined by the consuming application, not this library.
2. **Line items are denormalized** — stored as an array attribute on the invoice document, not as a separate collection. This simplifies queries and ensures invoice immutability.
3. **Fixed coupons credit wallets** — when a fixed-amount coupon is applied, the amount is credited to the customer's wallet immediately, creating a `coupon_credit` transaction.
4. **All money movements are transactions** — the transactions collection serves as a unified financial ledger for auditing and reconciliation.
5. **Namespace isolation** — collections use the database namespace for tenant isolation; no hardcoded collection name prefixes.
