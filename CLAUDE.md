# CLAUDE.md - Utopia Billing Library

## Project Overview

`utopia-php/billing` is a generic billing library for subscription management, invoicing, coupons/discounts, wallets, and unified transactions. Part of the Utopia Framework ecosystem. No application-specific logic — plans and pricing stay config-driven in the consuming app.

## Architecture

### Pattern
Facade wrapping abstract Adapter with Database adapter (same as `utopia-php/audit` and `utopia-php/abuse`).

```
Billing (facade) → Adapter (abstract) → Adapter\Database (uses utopia-php/database)
```

### Dependencies
- `utopia-php/database` — persistence (collections, documents, queries)
- `utopia-php/pay` — Invoice/Credit/Discount calculation utilities moved here from Pay. Pay itself is NOT a dependency — the app coordinates payment gateway calls.

### Namespace
`Utopia\Billing` — PSR-4: `Utopia\\Billing\\` → `src/Billing/`

---

## Collections

6 collections, namespace-isolated via `$db->setNamespace()` (no hardcoded prefix — follows `audit`, `abuse`, `usage` convention).

### subscriptions

```php
[
    'id'                    => string,
    'entityId'              => string,      // who owns this (team, user, org)
    'entityType'            => string,      // 'organization', 'user', etc.
    'planId'                => string,      // current active plan
    'status'                => string,      // see State Machine below

    // Billing period
    'currentPeriodStart'    => datetime,
    'currentPeriodEnd'      => datetime,

    // Trial
    'trialStart'            => ?datetime,
    'trialEnd'              => ?datetime,

    // Pending plan changes (upgrade/downgrade)
    'pendingPlanId'         => ?string,     // requested new plan
    'pendingChangeType'     => ?string,     // 'upgrade' | 'downgrade'
    'pendingChangedAt'      => ?datetime,   // when change was requested
    'pendingExpiresAt'      => ?datetime,   // auto-expire for upgrades (23h default)
    'pendingInvoiceId'      => ?string,     // upgrade invoice awaiting payment

    // Budget (usage-based spending cap)
    'budget'                => ?float,      // dollar cap per cycle (null = unlimited)
    'budgetUsed'            => float,       // current cycle usage total (app updates this)
    'budgetLimitReached'    => bool,        // computed: budgetUsed >= budget

    // Dunning (failed payment tracking)
    'failedPaymentAttempts' => int,
    'nextRetryAt'           => ?datetime,
    'lastFailedAt'          => ?datetime,

    // Cancellation
    'cancelAtPeriodEnd'     => bool,

    'metadata'              => array,
]
```

### invoices

Invoices are created at cycle END only. No "pending" invoices mid-cycle. Invoice is immutable once created.

```php
[
    'id'                    => string,
    'subscriptionId'        => ?string,     // null for one-off (domain, addon, wallet topup)
    'referenceInvoiceId'    => ?string,     // set for credit notes (points to original)
    'entityId'              => string,
    'type'                  => string,      // 'subscription', 'domain_purchase', 'wallet_topup', 'credit_note', etc.
    'number'                => string,      // configurable format: INV-{year}-{sequence}
    'status'                => string,      // 'draft', 'finalized', 'paid', 'failed', 'voided'

    // Line items (denormalized — see Line Item Structure below)
    'items'                 => array,

    // Totals (computed during finalization)
    'subtotal'              => float,       // sum of plan + usage + addon items
    'discountTotal'         => float,       // sum of discount items (negative)
    'taxTotal'              => float,       // sum of tax items
    'total'                 => float,       // subtotal + discountTotal + taxTotal
    'walletDeducted'        => float,       // deducted from wallet during payment
    'gatewayCharged'        => float,       // charged to payment gateway

    'currency'              => string,
    'dueDate'               => ?datetime,
    'paidAt'                => ?datetime,
    'metadata'              => array,
]
```

### coupons

Coupon = reusable template (definition only, not application state).

```php
[
    'id'                    => string,
    'code'                  => string,      // unique, user-facing
    'type'                  => string,      // 'fixed' | 'percentage'
    'value'                 => float,       // $20 or 50 (%)
    'currency'              => ?string,     // for fixed type
    'duration'              => string,      // 'once' | 'repeating' | 'forever'
    'durationInCycles'      => ?int,        // only for 'repeating'
    'maxRedemptions'        => ?int,        // total cap across all entities
    'timesRedeemed'         => int,         // counter
    'expiresAt'             => ?datetime,   // coupon template expiry
    'scope'                 => ?array,      // { 'planIds': [...], 'resources': [...] }
    'active'                => bool,
    'metadata'              => array,
]
```

### discounts

Discount = applied instance per subscription. Tracks cycle countdown (Lago pattern).

```php
[
    'id'                    => string,
    'couponId'              => string,
    'subscriptionId'        => string,
    'entityId'              => string,
    'type'                  => string,      // 'fixed' | 'percentage' (inherited from coupon)
    'value'                 => float,       // inherited from coupon
    'duration'              => string,      // 'once' | 'repeating' | 'forever'
    'scope'                 => ?array,      // { 'resources': ['bandwidth'] } or null (invoice-level)
    'cyclesTotal'           => ?int,        // original count (null for forever)
    'cyclesRemaining'       => ?int,        // decremented each cycle (null for forever)
    'status'                => string,      // 'active' | 'exhausted' | 'cancelled'
    'appliedAt'             => datetime,
    'exhaustedAt'           => ?datetime,
    'cancelledAt'           => ?datetime,
    'metadata'              => array,
]
```

**Duration behavior:**
- `once`: cyclesRemaining 1 → 0 → status: exhausted
- `repeating(3)`: cyclesRemaining 3 → 2 → 1 → 0 → status: exhausted
- `forever`: cyclesRemaining null, applied every cycle until admin sets status: cancelled

**Scope determines discount level:**
- `scope: null` → invoice-level (applied to subtotal)
- `scope: { resources: ['bandwidth'] }` → line-level (only matching items)

### wallets

User-funded prepaid balance. Fixed coupon credits also go here.

```php
[
    'id'                    => string,
    'entityId'              => string,
    'balance'               => float,
    'currency'              => string,
    'metadata'              => array,
]
```

### transactions

Unified ledger for ALL money movements. Every transaction has an `invoiceId`.

```php
[
    'id'                    => string,
    'entityId'              => string,
    'invoiceId'             => string,      // always present (wallet topups generate receipt invoice)
    'type'                  => string,      // see types below
    'amount'                => float,
    'status'                => string,      // 'pending', 'succeeded', 'failed'
    'walletId'              => ?string,     // for wallet operations
    'providerPaymentId'     => ?string,     // for gateway operations (Stripe intent ID)
    'description'           => string,
    'metadata'              => array,
]
```

**Transaction types:**

| Type | Wallet effect | Linked to |
|---|---|---|
| `gateway_charge` | none | invoiceId, providerPaymentId |
| `gateway_refund` | none | invoiceId, providerPaymentId |
| `wallet_topup` | +amount | invoiceId (topup receipt), providerPaymentId |
| `wallet_deduction` | -amount | invoiceId |
| `wallet_refund` | +amount | invoiceId |
| `coupon_credit` | +amount | couponId, expiresAt (optional) |
| `credit_expiry` | -amount | relatedTransactionId |

---

## Line Item Structure

Everything is a line item. Consistent structure regardless of type.

```php
[
    'type'          => string,      // 'plan', 'usage', 'addon', 'discount', 'tax', 'proration', 'refund'
    'description'   => string,      // human-readable
    'resource'      => ?string,     // app-defined key: 'bandwidth', 'executions', 'storage', etc.
    'quantity'      => ?float,      // null for non-quantity items (discount, tax)
    'unit'          => ?string,     // 'GB', 'executions', 'hours', etc.
    'unitPrice'     => ?float,      // null for non-quantity items
    'amount'        => float,       // final amount (negative for discounts/refunds/proration credits)
    'discountId'    => ?string,     // links to applied discount (for discount line items)
    'rate'          => ?float,      // for tax items (e.g., 17.0 for 17%)
    'metadata'      => array,
]
```

**Example invoice items:**
```php
[
    ['type' => 'plan',     'description' => 'Pro Plan - April 2026',      'amount' => 15.00],
    ['type' => 'usage',    'description' => 'Bandwidth (42.5 GB extra)',   'amount' => 3.40,   'resource' => 'bandwidth', 'quantity' => 42.5, 'unit' => 'GB', 'unitPrice' => 0.08],
    ['type' => 'usage',    'description' => 'Executions (150K extra)',     'amount' => 0.30,   'resource' => 'executions', 'quantity' => 150000, 'unitPrice' => 0.000002],
    ['type' => 'addon',    'description' => 'HIPAA BAA',                   'amount' => 350.00],
    ['type' => 'discount', 'description' => 'BW_HALF (50% off bandwidth)','amount' => -1.70,  'resource' => 'bandwidth', 'discountId' => 'disc_xyz'],
    ['type' => 'discount', 'description' => 'LOYAL10 (10% off)',          'amount' => -36.73, 'discountId' => 'disc_abc'],
    ['type' => 'tax',      'description' => 'VAT (17%)',                   'amount' => 55.04,  'rate' => 17.0],
]
```

---

## Subscription State Machine

```
incomplete → active (payment succeeds) | incomplete_expired (23h timeout)
trialing → active (trial ends + payment succeeds)
active → past_due (renewal fails) | canceling (cancel at period end)
past_due → active (retry succeeds) | suspended (max retries exhausted)
canceling → canceled (period ends)
```

| State | Meaning |
|---|---|
| `incomplete` | Created, first payment pending (3DS/processing). Auto-expires after timeout. |
| `incomplete_expired` | First payment not resolved. Terminal — create new subscription. |
| `trialing` | In trial, no payment yet. Full access. |
| `active` | Current, payment up to date. Full access. |
| `past_due` | Renewal payment failed, retrying. Access during grace period. |
| `canceling` | Active until end of current period. |
| `canceled` | Terminated. |
| `suspended` | Payment retries exhausted. Restricted access. |

### Pending Upgrades

Upgrades do NOT apply immediately. Subscription stores pending change, keeps old plan until payment confirms. Follows Stripe's `pending_if_incomplete` pattern.

1. `requestUpgrade()` → sets pendingPlanId, creates upgrade invoice, emits event
2. Payment succeeds → `finalizeUpgrade()` → applies plan, clears pending
3. Payment fails → `cancelUpgrade()` → clears pending, voids invoice
4. Timeout (configurable, default 23h) → auto-clears pending, voids invoice

### Downgrades

Deferred to end of cycle. Customer keeps current plan until period ends.

1. `requestDowngrade()` → sets pendingPlanId + pendingChangeType: 'downgrade'
2. At cycle end → `applyPendingDowngrade()` → switches plan
3. Cancel before end → `cancelDowngrade()` → clears pending

### Dunning (Failed Payment)

Library tracks retry state. App schedules actual retries.

- `recordPaymentFailure()` → increments attempts, transitions to `past_due`
- `recordPaymentSuccess()` → resets counters, back to `active`
- `suspendSubscription()` → max retries exhausted, transitions to `suspended`

### Budget / Spending Caps

Budget caps usage-based charges per cycle. No invoice exists mid-cycle — budget tracked on subscription.

- `setBudget()` → sets dollar cap (null = unlimited)
- `updateBudgetUsed()` → app calls after each aggregation. Library computes `budgetLimitReached`, emits events.
- `budgetUsed` resets to 0 on renewal
- Library does NOT enforce limits — only tracks and emits events

---

## Invoice Lifecycle

**Critical:** Invoices are created at cycle END only. No "pending" invoices mid-cycle.

- Mid-cycle: No invoice. App tracks usage via aggregation. App calls `updateBudgetUsed()`.
- Cycle end: App computes final amounts, creates invoice with line items, library finalizes.
- Invoice is immutable once created.

### Finalization Flow

```
1. App computes usage from aggregation pipeline
2. App creates invoice with line items via createInvoice()
3. App calls finalizeInvoice() — library does:
   a. Queries active discounts for subscription
   b. Applies line-level discounts (scope.resources match)
   c. Applies invoice-level discounts (scope is null)
   d. Decrements discount cyclesRemaining
   e. Adds tax line items (app provides rate + taxable types)
   f. Computes subtotal, discountTotal, taxTotal, total
   g. Generates invoice number
   h. Emits 'invoice.finalized'
4. App orchestrates payment: wallet deduction → gateway charge
5. App calls markInvoicePaid() or markInvoiceFailed()
```

### Credit Notes

Invoices with `type: 'credit_note'` and `referenceInvoiceId`. Negative line items. No separate collection.

### Invoice Types (app-defined, library is type-agnostic)
- `subscription` — recurring billing
- `domain_purchase` — one-off
- `domain_renewal` — one-off
- `addon_*` — one-off addon charge
- `wallet_topup` — wallet funding receipt
- `credit_note` — refund document

### Amount Calculation Split

| Concern | Library | App |
|---|---|---|
| Usage aggregation | | App (aggregation pipeline) |
| Usage → amount (qty × price) | | App (knows plan pricing) |
| Discount application | finalizeInvoice() | |
| Tax computation | finalizeInvoice() (app provides rate) | |
| Total calculation | finalizeInvoice() | |
| Budget tracking | updateBudgetUsed() | Calls after aggregation |
| Budget enforcement | Emits events | Blocks API calls |

---

## Facade API

```php
class Billing
{
    public function __construct(Adapter $adapter, array $options = []) {}
    // Options: 'invoiceNumberFormat' => 'INV-{year}-{sequence}'

    public function setup(): void  // Creates 6 collections

    // Events
    public function on(string $event, callable $callback): self
    // Events: subscription.created, subscription.upgraded, subscription.downgraded,
    //         subscription.canceled, subscription.suspended, subscription.renewed,
    //         subscription.upgrade_pending, subscription.upgrade_failed, subscription.upgrade_expired,
    //         subscription.downgrade_scheduled, subscription.budget_warning, subscription.budget_reached,
    //         invoice.finalized, invoice.paid, invoice.failed, invoice.voided,
    //         discount.applied, discount.exhausted, discount.cancelled,
    //         wallet.funded, wallet.deducted, coupon.redeemed,
    //         payment.failed, payment.retry_scheduled, transaction.created

    // --- Subscriptions ---
    public function createSubscription(string $entityId, string $planId, ?DateTime $trialEnd = null): Subscription
    public function getSubscription(string $id): Subscription
    public function getActiveSubscription(string $entityId): ?Subscription
    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = true): Subscription
    public function renewSubscription(string $subscriptionId): Subscription

    // Plan changes
    public function requestUpgrade(string $subscriptionId, string $newPlanId, ?DateTime $expiresAt = null): Subscription
    public function finalizeUpgrade(string $subscriptionId): Subscription
    public function cancelUpgrade(string $subscriptionId): Subscription
    public function requestDowngrade(string $subscriptionId, string $newPlanId): Subscription
    public function cancelDowngrade(string $subscriptionId): Subscription
    public function applyPendingDowngrade(string $subscriptionId): Subscription

    // Budget
    public function setBudget(string $subscriptionId, ?float $budget): Subscription
    public function updateBudgetUsed(string $subscriptionId, float $amount): Subscription

    // Dunning
    public function recordPaymentFailure(string $subscriptionId): Subscription
    public function recordPaymentSuccess(string $subscriptionId): Subscription
    public function suspendSubscription(string $subscriptionId): Subscription

    // --- Invoices ---
    public function createInvoice(string $entityId, string $type, ?string $subscriptionId = null, array $items = []): Invoice
    public function getInvoice(string $id): Invoice
    public function listInvoices(string $entityId, array $filters = []): array
    public function addInvoiceItem(string $invoiceId, array $item): Invoice
    public function finalizeInvoice(string $invoiceId, array $options = []): Invoice
    // Options: 'taxRate', 'taxDescription', 'taxableTypes' => ['plan', 'usage', 'addon']
    public function markInvoicePaid(string $invoiceId, string $paymentId): Invoice
    public function markInvoiceFailed(string $invoiceId): Invoice
    public function voidInvoice(string $invoiceId): Invoice
    public function createCreditNote(string $referenceInvoiceId, array $refundItems): Invoice

    // --- Coupons ---
    public function createCoupon(string $code, string $type, float $value, string $duration, ?int $durationInCycles = null, array $options = []): Coupon
    public function getCoupon(string $code): Coupon
    public function listCoupons(array $filters = []): array
    public function deactivateCoupon(string $code): Coupon

    // --- Discounts ---
    public function applyDiscount(string $couponCode, string $subscriptionId, string $entityId): Discount
    public function getDiscount(string $id): Discount
    public function listActiveDiscounts(string $subscriptionId): array
    public function cancelDiscount(string $id): Discount

    // --- Wallet ---
    public function getOrCreateWallet(string $entityId, string $currency = 'USD'): Wallet
    public function getWalletBalance(string $entityId): float
    public function addFunds(string $entityId, string $invoiceId, float $amount, string $description = ''): Transaction
    public function deductFunds(string $entityId, string $invoiceId, float $amount, string $description = ''): Transaction
    public function refundToWallet(string $entityId, string $invoiceId, float $amount, string $description = ''): Transaction

    // --- Transactions ---
    public function createTransaction(string $entityId, string $invoiceId, string $type, float $amount, ?string $walletId = null, ?string $providerPaymentId = null): Transaction
    public function getTransaction(string $id): Transaction
    public function listTransactions(string $entityId, array $filters = []): array
    public function listInvoiceTransactions(string $invoiceId): array

    // --- Period ---
    public function getCurrentPeriod(string $subscriptionId): Period
    public function calculateProration(string $subscriptionId, string $newPlanId, float $newPrice): float
}
```

---

## Invoice Rendering

- `Render\Renderer` — abstract base class
- `Render\HTML` — built-in, uses `.phtml` templates (zero deps)
- `Render\PDF` — optional, wraps mPDF/dompdf (composer suggest)
- Default template in `templates/invoice.phtml`
- Custom template: pass path to `render($invoice, '/path/to/custom.phtml')`
- Template receives: `$invoice`, `$items`, `$entity` (app-provided), `$issuer` (app-provided)

---

## Directory Structure

```
src/Billing/
    Billing.php              # Main facade
    Adapter.php              # Abstract adapter
    Adapter/
        Database.php         # Database adapter (setup + all CRUD)
    Subscription.php
    SubscriptionStatus.php   # Enum: incomplete, active, past_due, etc.
    Invoice.php
    InvoiceStatus.php        # Enum: draft, finalized, paid, etc.
    Coupon.php
    CouponType.php           # Enum: fixed, percentage
    CouponDuration.php       # Enum: once, repeating, forever
    Discount.php
    DiscountStatus.php       # Enum: active, exhausted, cancelled
    Credit.php               # Calculation utility (moved from Pay)
    Transaction.php
    TransactionType.php      # Enum: gateway_charge, wallet_topup, etc.
    TransactionStatus.php    # Enum: pending, succeeded, failed
    ChangeType.php           # Enum: upgrade, downgrade
    Wallet.php
    Period.php               # Value object: start, end, duration math
    Exception.php
    Render/
        Renderer.php         # Abstract
        HTML.php             # Built-in HTML renderer
templates/
    invoice.phtml            # Default invoice template
tests/
    Unit/
        Billing/
            InMemoryAdapter.php    # Shared in-memory adapter for unit tests
            BillingTest.php        # Facade tests (subscriptions, invoices, coupons, etc.)
            SubscriptionTest.php   # Subscription model tests
            InvoiceTest.php        # Invoice model tests
            CouponTest.php         # Coupon model tests
            DiscountTest.php       # Discount model tests
            TransactionTest.php    # Transaction model tests
            WalletTest.php         # Wallet model tests
            CreditTest.php         # Credit calculation tests
            PeriodTest.php         # Period value object tests
            EnumTest.php           # Enum behavior tests
            Render/
                HTMLTest.php       # HTML renderer tests
    E2E/
        Billing/
            BillingTest.php        # Full e2e against MariaDB via Docker
```

---

## Coding Conventions

- `declare(strict_types=1);` in every file
- PHP 8.1+ (enums, union types, named arguments, match expressions)
- PSR-4 autoloading
- PHPUnit for tests
- `$db->getAuthorization()->skip()` for internal DB operations
- `setup()` creates all 6 collections with attributes and indexes
- PHPDoc on all public methods
- Value objects for Period, line items
- No application-specific logic — library is generic

## Design Decisions

1. **Generic library** — no Appwrite concepts. Plans/pricing are app config.
2. **Denormalized line items** — array on invoice, not separate collection.
3. **Fixed coupons credit wallet** — immediately, via `coupon_credit` transaction.
4. **Percentage coupons create discounts** — tracked with cycle countdown, applied during finalization.
5. **Unified transactions** — single ledger for all money movements. Every transaction has invoiceId.
6. **Namespace isolation** — `$db->setNamespace()` for multi-tenancy, no name prefixes.
7. **Subscription state = payment state** — Stripe model, not Lago (decoupled). One field to check.
8. **Pending upgrades** — don't apply until payment confirms. Stripe's `pending_if_incomplete`.
9. **Deferred downgrades** — applied at cycle end.
10. **No pending invoices** — created at cycle end only. Immutable once created.
11. **Budget enforcement is app-layer** — library tracks, app enforces.
12. **Invoice/Credit/Discount moved from Pay** — they're billing arithmetic, not gateway concerns.
