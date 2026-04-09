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
Customer's subscription to a plan. Has 8 states and supports pending plan changes, budgets, and dunning.

**States**: `incomplete`, `incomplete_expired`, `trialing`, `active`, `past_due`, `canceling`, `canceled`, `suspended`

**Key fields:**
- Core: `entityId`, `planId`, `status`, `currentPeriodStart`, `currentPeriodEnd`, `trialStart`, `trialEnd`
- Pending changes: `pendingPlanId`, `pendingChangeType` (upgrade/downgrade), `pendingChangedAt`, `pendingExpiresAt`, `pendingInvoiceId`
- Budget: `budget` (nullable, dollar cap), `budgetUsed`, `budgetLimitReached`
- Dunning: `failedPaymentAttempts`, `nextRetryAt`, `lastFailedAt`
- Cancellation: `cancelAtPeriodEnd`

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

## Subscription Lifecycle

### State Machine

```
incomplete → active (payment succeeds) | incomplete_expired (23h timeout)
trialing → active (trial ends + payment succeeds)
active → past_due (renewal fails) | canceling (cancel at period end)
past_due → active (retry succeeds) | suspended (max retries exhausted)
canceling → canceled (period ends)
```

### Pending Upgrades (Stripe's `pending_if_incomplete` pattern)

Upgrades do NOT apply immediately. The subscription stores a pending change and keeps the old plan active until payment confirms.

1. `requestUpgrade(subscriptionId, newPlanId)` → sets `pendingPlanId`, creates upgrade invoice, emits `subscription.upgrade_pending`
2. Payment succeeds → `finalizeUpgrade(subscriptionId)` → applies plan change, clears pending, emits `subscription.upgraded`
3. Payment fails → `cancelUpgrade(subscriptionId)` → clears pending, voids invoice, emits `subscription.upgrade_failed`
4. Timeout (23h) → auto-clears pending, voids invoice, emits `subscription.upgrade_expired`

### Downgrades (Deferred to End of Cycle)

Downgrades are deferred. Customer keeps current plan until period ends.

1. `requestDowngrade(subscriptionId, newPlanId)` → sets `pendingPlanId`, `pendingChangeType: 'downgrade'`
2. At cycle end during renewal → `applyPendingDowngrade(subscriptionId)` → switches plan, emits `subscription.downgraded`
3. Cancel before cycle end → `cancelDowngrade(subscriptionId)` → clears pending

### Failed Payment / Dunning

Library tracks retry state. App schedules actual retries (infra-specific).

- `recordPaymentFailure(subscriptionId)` → increments `failedPaymentAttempts`, computes `nextRetryAt`, transitions to `past_due`
- `recordPaymentSuccess(subscriptionId)` → resets counters, transitions back to `active`
- `suspendSubscription(subscriptionId)` → max retries exhausted, transitions to `suspended`
- Events: `payment.failed`, `payment.retry_scheduled`, `subscription.suspended`

### Budget / Spending Caps

- `setBudget(subscriptionId, amount)` → sets dollar cap (null = unlimited)
- `updateBudgetUsed(subscriptionId, amount)` → tracks usage against cap
- Library computes `budgetLimitReached` and emits `subscription.budget_reached`
- App enforces what happens when budget is hit (block API, alert, etc.)

## Invoice Finalization Flow

```
1. App creates invoice with line items (plan, usage, addons)
2. Library applies line-level discounts (scope.resources matches item.resource)
3. Library applies invoice-level discounts (scope is null)
4. Library adds tax line items (app provides rate + taxable types)
5. Library computes subtotal, discountTotal, taxTotal, total
6. Library generates invoice number and emits 'invoice.finalized'
7. App orchestrates payment: wallet deduction → gateway charge
```

## Important Design Decisions

1. **Library is generic** — no Appwrite-specific logic. Plans, products, and pricing tiers are defined by the consuming application, not this library.
2. **Line items are denormalized** — stored as an array attribute on the invoice document, not as a separate collection. This simplifies queries and ensures invoice immutability.
3. **Fixed coupons credit wallets** — when a fixed-amount coupon is applied, the amount is credited to the customer's wallet immediately, creating a `coupon_credit` transaction.
4. **Percentage coupons create applied discounts** — tracked in the `discounts` collection with cycle countdown, applied as line items during invoice finalization.
5. **All money movements are transactions** — the transactions collection serves as a unified financial ledger for auditing and reconciliation. Every transaction has an `invoiceId`.
6. **Namespace isolation** — collections use the database namespace for tenant isolation; no hardcoded collection name prefixes.
7. **Subscription state tracks payment state** — unlike Lago (decoupled), we follow Stripe's model where subscription status reflects payment health. Downstream services check one field.
8. **Pending upgrades don't apply until payment confirms** — Stripe's `pending_if_incomplete` pattern prevents resource access without payment.
9. **Downgrades deferred to end of cycle** — customer already paid for current plan.
10. **Budget enforcement is app-layer** — library tracks budget/used/reached, app decides what to restrict.
