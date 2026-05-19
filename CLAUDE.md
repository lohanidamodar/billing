# CLAUDE.md - Utopia Billing Library

## Project Overview

`utopia-php/billing` is a generic billing library for subscription management, invoicing, payment orchestration, coupons/discounts, wallets, and unified transactions. Part of the Utopia Framework ecosystem. No application-specific logic — plans and pricing stay config-driven in the consuming app.

## Architecture

### Pattern
Facade with two adapter slots — **persistence** and **payment gateway** — following the same Facade + Adapter pattern used across all utopia-php libraries (`audit`, `abuse`, `database`, `messaging`, etc.).

```
Billing (facade)
  ├── Adapter (abstract)        → persistence layer
  │     └── Adapter\Database    → uses utopia-php/database
  │
  └── Payment (abstract)        → payment gateway (optional)
        ├── Payment\Pay         → wraps utopia-php/pay (Stripe, etc.)
        └── Payment\Manual      → wallet-only / offline (default when no gateway)
```

### Dependencies
- `utopia-php/database` (required) — persistence (collections, documents, queries)
- `utopia-php/pay` (suggested) — only needed when using `Payment\Pay` adapter. Use branch `claude/improve-utopia-library-EdGjh` for structured response models.

### Namespace
`Utopia\Billing` — PSR-4: `Utopia\\Billing\\` → `src/Billing/`

---

## Collections

6 collections, namespace-isolated via `$db->setNamespace()` (no hardcoded prefix — follows `audit`, `abuse` convention).

### subscriptions

```php
[
    'id'                    => string,
    'entityId'              => string,      // who owns this (team, user, org)
    'entityType'            => string,      // 'organization', 'user', etc.
    'planId'                => string,      // current active plan
    'status'                => string,      // SubscriptionStatus enum

    // Billing period
    'currentPeriodStart'    => datetime,
    'currentPeriodEnd'      => datetime,

    // Trial
    'trialStart'            => ?datetime,
    'trialEnd'              => ?datetime,

    // Pending plan changes (upgrade/downgrade)
    'pendingPlanId'         => ?string,     // requested new plan
    'pendingChangeType'     => ?string,     // ChangeType enum: 'upgrade' | 'downgrade'
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

    'metadata'              => string,      // JSON-encoded
]
```

### invoices

Invoices are created at cycle END only. No "pending" invoices mid-cycle. Invoice is immutable once finalized.

```php
[
    'id'                    => string,
    'subscriptionId'        => ?string,     // null for one-off invoices
    'referenceInvoiceId'    => ?string,     // set for credit notes (points to original)
    'entityId'              => string,
    'type'                  => string,      // app-defined: 'subscription', 'wallet_topup', 'credit_note', etc.
    'number'                => string,      // configurable format: INV-{year}-{sequence}
    'status'                => string,      // InvoiceStatus enum

    // Line items (denormalized — see Line Item Structure below)
    'items'                 => string,      // JSON-encoded array

    // Totals (computed during finalization)
    'subtotal'              => float,
    'discountTotal'         => float,
    'taxTotal'              => float,
    'total'                 => float,
    'walletDeducted'        => float,       // deducted from wallet during payment
    'gatewayCharged'        => float,       // charged to payment gateway

    'currency'              => string,
    'dueDate'               => ?datetime,
    'paidAt'                => ?datetime,
    'metadata'              => string,      // JSON-encoded
]
```

### coupons

Coupon = reusable template (definition only, not application state).

```php
[
    'id'                    => string,
    'code'                  => string,      // unique, user-facing
    'type'                  => string,      // CouponType enum: 'fixed' | 'percentage'
    'value'                 => float,       // $20 or 50 (%)
    'currency'              => ?string,     // for fixed type
    'duration'              => string,      // CouponDuration enum: 'once' | 'repeating' | 'forever'
    'durationInCycles'      => ?int,        // only for 'repeating'
    'maxRedemptions'        => ?int,        // total cap across all entities
    'timesRedeemed'         => int,         // counter
    'expiresAt'             => ?datetime,   // coupon template expiry
    'scope'                 => ?string,     // JSON-encoded: { 'planIds': [...], 'resources': [...] }
    'active'                => bool,
    'metadata'              => string,      // JSON-encoded
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
    'type'                  => string,      // CouponType enum (inherited from coupon)
    'value'                 => float,       // inherited from coupon
    'duration'              => string,      // CouponDuration enum (inherited from coupon)
    'scope'                 => ?string,     // JSON-encoded: { 'resources': ['bandwidth'] } or null
    'cyclesTotal'           => ?int,        // original count (null for forever)
    'cyclesRemaining'       => ?int,        // decremented each cycle (null for forever)
    'status'                => string,      // DiscountStatus enum
    'appliedAt'             => datetime,
    'exhaustedAt'           => ?datetime,
    'cancelledAt'           => ?datetime,
    'metadata'              => string,      // JSON-encoded
]
```

### wallets

User-funded prepaid balance. Fixed coupon credits also go here.

```php
[
    'id'                    => string,
    'entityId'              => string,
    'balance'               => float,
    'currency'              => string,
    'metadata'              => string,      // JSON-encoded
]
```

### transactions

Unified ledger for ALL money movements. Every transaction has an `invoiceId`.

```php
[
    'id'                    => string,
    'entityId'              => string,
    'invoiceId'             => string,      // always present
    'type'                  => string,      // TransactionType enum
    'amount'                => float,
    'status'                => string,      // TransactionStatus enum
    'walletId'              => ?string,     // for wallet operations
    'providerPaymentId'     => ?string,     // for gateway operations (Stripe payment intent ID)
    'clientSecret'          => ?string,     // for 3DS/SCA frontend confirmation
    'description'           => string,
    'metadata'              => string,      // JSON-encoded
]
```

**Transaction types:**

| Type | Wallet effect | Linked to |
|---|---|---|
| `gateway_charge` | none | invoiceId, providerPaymentId |
| `gateway_refund` | none | invoiceId, providerPaymentId |
| `wallet_topup` | +amount | invoiceId, providerPaymentId |
| `wallet_deduction` | -amount | invoiceId |
| `wallet_refund` | +amount | invoiceId |
| `coupon_credit` | +amount | couponId |
| `credit_expiry` | -amount | relatedTransactionId |

---

## Enums (PHP 8.1 backed enums)

Closed state sets use backed enums. Open/extensible types (invoice type, entity type) use plain strings.

| Enum | Values |
|---|---|
| `SubscriptionStatus` | incomplete, incomplete_expired, trialing, active, past_due, canceling, canceled, suspended |
| `InvoiceStatus` | draft, finalized, paid, failed, voided |
| `DiscountStatus` | active, exhausted, cancelled |
| `TransactionStatus` | pending, succeeded, failed |
| `TransactionType` | gateway_charge, gateway_refund, wallet_topup, wallet_deduction, wallet_refund, coupon_credit, credit_expiry |
| `CouponType` | fixed, percentage |
| `CouponDuration` | once, repeating, forever |
| `ChangeType` | upgrade, downgrade |
| `BillingEvent` | All event names for the listener system |

---

## Subscription State Machine

```
incomplete → active (payment succeeds) | incomplete_expired (23h timeout)
trialing → active (trial ends + payment succeeds)
active → past_due (renewal fails) | canceling (cancel at period end)
past_due → active (retry succeeds) | suspended (max retries exhausted)
canceling → canceled (period ends)
```

### Pending Upgrades

Upgrades do NOT apply immediately. Subscription stores pending change, keeps old plan until payment confirms (Stripe's `pending_if_incomplete` pattern).

### Downgrades

Deferred to end of cycle. Customer keeps current plan until period ends.

### Dunning (Failed Payment)

Library tracks retry state. App schedules actual retries.

### Budget / Spending Caps

Library tracks usage and computes `budgetLimitReached`. App enforces limits.

---

## Payment Orchestration

The library owns the full payment flow — wallet deduction, gateway charge, transaction recording, and invoice state transitions. The app just calls `payInvoice()`.

### Payment Adapter

Abstract `Payment` adapter defines the gateway contract. Concrete adapters handle specific providers.

```php
abstract class Payment
{
    abstract public function charge(float $amount, string $currency, string $customerId, ?string $paymentMethodId = null): PaymentResponse;
    abstract public function refund(string $providerPaymentId, ?float $amount = null, ?string $reason = null): PaymentResponse;
    abstract public function getName(): string;
}
```

**`PaymentResponse`** — value object returned by payment adapters:
```php
class PaymentResponse
{
    public readonly string $status;             // succeeded, requires_action, processing, failed
    public readonly ?string $providerPaymentId; // pi_xxx
    public readonly ?string $clientSecret;      // pi_xxx_secret_yyy (for frontend 3DS/SCA)
    public readonly ?string $redirectUrl;        // for redirect-based auth (non-Stripe providers)
    public readonly ?string $errorCode;
    public readonly ?string $errorMessage;
}
```

**Adapters:**
- `Payment\Pay` — wraps `utopia-php/pay` (Stripe). Translates `Pay\Payment\Payment` → `PaymentResponse`.
- `Payment\Manual` — wallet-only or offline. Always returns `succeeded` (no gateway call).

### Payment Flow: `payInvoice()`

```
1. Get finalized invoice (throw if not finalized)
2. Calculate total
3. Wallet-first: deduct min(walletBalance, total) → create wallet_deduction transaction
4. If remaining > 0: call Payment->charge() → get PaymentResponse
   a. succeeded → create gateway_charge transaction (succeeded), mark invoice paid
   b. requires_action → create gateway_charge transaction (pending), return response with clientSecret
   c. processing → create gateway_charge transaction (pending), return response
   d. failed → create gateway_charge transaction (failed), record dunning state
5. If remaining == 0 (fully wallet-paid): mark invoice paid
6. Update invoice walletDeducted + gatewayCharged
7. Return PaymentResponse
```

### Async Payment Confirmation

For 3DS/SCA and async processing, the app receives webhook confirmations and calls:

- `confirmPayment(invoiceId, providerPaymentId)` — marks transaction succeeded, invoice paid
- `failPayment(invoiceId, providerPaymentId)` — marks transaction failed, records dunning
- `retryPayment(invoiceId, customerId)` — re-attempts via Payment adapter

### Refund Flow: `refundInvoice()`

```
1. Get paid invoice
2. If gateway amount > 0: call Payment->refund() → create gateway_refund transaction
3. If wallet amount > 0: credit wallet → create wallet_refund transaction
4. Create credit note invoice
5. Return credit note
```

---

## Invoice Lifecycle

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
4. App calls payInvoice() — library does:
   a. Wallet deduction (wallet-first)
   b. Gateway charge (via Payment adapter)
   c. Transaction recording
   d. Invoice state → paid (or pending for 3DS)
5. If 3DS: app receives webhook → calls confirmPayment() or failPayment()
```

---

## Event System

Follows the same pattern as `utopia-php/database` — named listeners with `on()` method.

```php
$billing->on(BillingEvent::InvoicePaid, 'notify-user', function (Invoice $invoice) {
    // send email
});
```

`BillingEvent` enum defines all event names:
- subscription.created, subscription.upgraded, subscription.downgraded, subscription.canceled, subscription.suspended, subscription.renewed
- subscription.upgrade_pending, subscription.upgrade_failed
- subscription.downgrade_scheduled
- subscription.budget_warning, subscription.budget_reached
- invoice.finalized, invoice.paid, invoice.failed, invoice.voided
- discount.applied, discount.exhausted, discount.cancelled
- wallet.funded, wallet.deducted
- coupon.redeemed
- payment.failed, payment.requires_action
- transaction.created

---

## Facade API

```php
class Billing
{
    public function __construct(Adapter $adapter, ?Payment $payment = null, array $options = []) {}

    public function setup(): void

    // Events (same pattern as utopia-php/database)
    public function on(BillingEvent $event, string $name, ?callable $callback): self

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
    public function voidInvoice(string $invoiceId): Invoice
    public function createCreditNote(string $referenceInvoiceId, array $refundItems): Invoice

    // --- Payment Orchestration (NEW) ---
    public function payInvoice(string $invoiceId, string $customerId, ?string $paymentMethodId = null): PaymentResponse
    public function confirmPayment(string $invoiceId, string $providerPaymentId): Invoice
    public function failPayment(string $invoiceId, string $providerPaymentId): Invoice
    public function retryPayment(string $invoiceId, string $customerId, ?string $paymentMethodId = null): PaymentResponse
    public function refundInvoice(string $invoiceId, ?float $amount = null, ?string $reason = null): Invoice

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
    public function addFunds(string $entityId, string $invoiceId, float $amount): Transaction
    public function deductFunds(string $entityId, string $invoiceId, float $amount): Transaction
    public function refundToWallet(string $entityId, string $invoiceId, float $amount): Transaction

    // --- Transactions ---
    public function createTransaction(string $entityId, string $invoiceId, string $type, float $amount, ?string $walletId = null, ?string $providerPaymentId = null): Transaction
    public function getTransaction(string $id): Transaction
    public function listTransactions(string $entityId, array $filters = []): array
    public function listInvoiceTransactions(string $invoiceId): array

    // --- Period ---
    public function getCurrentPeriod(string $subscriptionId): Period
    public function calculateProration(string $subscriptionId, string $newPlanId, float $newPrice): float

    // --- Retained for direct control (markInvoicePaid/Failed still public) ---
    public function markInvoicePaid(string $invoiceId, string $paymentId): Invoice
    public function markInvoiceFailed(string $invoiceId): Invoice
}
```

---

## Invoice Rendering

- `Render\Renderer` — abstract base class
- `Render\HTML` — built-in, uses `.phtml` templates (zero deps)
- Default template in `templates/invoice.phtml`
- Custom template: pass path in options array
- Template receives: `$invoice`, `$items`, `$entity` (app-provided), `$issuer` (app-provided)

---

## Directory Structure

```
src/Billing/
    Billing.php              # Main facade
    Adapter.php              # Abstract persistence adapter
    Adapter/
        Database.php         # Database adapter (utopia-php/database)
    Payment.php              # Abstract payment adapter
    Payment/
        Pay.php              # Pay adapter (utopia-php/pay)
        Manual.php           # Manual/wallet-only adapter
    PaymentResponse.php      # Value object for payment results
    BillingEvent.php         # Enum: all event names
    Subscription.php
    SubscriptionStatus.php   # Enum
    Invoice.php
    InvoiceStatus.php        # Enum
    Coupon.php
    CouponType.php           # Enum
    CouponDuration.php       # Enum
    Discount.php
    DiscountStatus.php       # Enum
    Credit.php               # Calculation utility
    Transaction.php
    TransactionType.php      # Enum
    TransactionStatus.php    # Enum
    ChangeType.php           # Enum
    Wallet.php
    Period.php               # Value object
    Exception.php
    Render/
        Renderer.php         # Abstract
        HTML.php             # Built-in HTML renderer
templates/
    invoice.phtml            # Default invoice template
tests/
    Unit/
        Billing/
            InMemoryAdapter.php
            BillingTest.php
            SubscriptionTest.php
            InvoiceTest.php
            CouponTest.php
            DiscountTest.php
            TransactionTest.php
            WalletTest.php
            CreditTest.php
            PeriodTest.php
            EnumTest.php
            Render/
                HTMLTest.php
    E2E/
        Billing/
            BillingTest.php
```

---

## Coding Conventions

- `declare(strict_types=1);` in every file
- PHP 8.1+ (enums, union types, named arguments, match expressions)
- PSR-12 code style (Pint with psr12 preset)
- PSR-4 autoloading
- PHPUnit for tests, camelCase test method names
- PHPStan level max
- `$db->getAuthorization()->skip()` for internal DB operations
- JSON encoding for metadata, items, scope fields (stored as VAR_STRING in DB)
- Value objects for Period, PaymentResponse, line items
- No application-specific logic — library is generic

## Design Decisions

1. **Generic library** — no Appwrite concepts. Plans/pricing are app config.
2. **Two adapter slots** — persistence (required) and payment (optional). Same pattern as database (adapter + cache).
3. **Library owns payment orchestration** — `payInvoice()` handles wallet-first deduction, gateway charge, transaction recording, and invoice state. App doesn't manually wire these together.
4. **3DS/SCA support** — `payInvoice()` returns `PaymentResponse` with `clientSecret` for frontend confirmation. App handles webhook → calls `confirmPayment()`.
5. **Denormalized line items** — JSON array on invoice, not separate collection.
6. **Fixed coupons credit wallet** — immediately, via `coupon_credit` transaction.
7. **Percentage coupons create discounts** — tracked with cycle countdown, applied during finalization.
8. **Unified transactions** — single ledger for all money movements. Every transaction has invoiceId.
9. **Event system** — `on()` with named listeners and `BillingEvent` enum, same pattern as `utopia-php/database`.
10. **Pay is optional** — `utopia-php/pay` in `suggest`, not `require`. Payment adapter is nullable; without it, wallet-only mode works. Use branch `claude/improve-utopia-library-EdGjh` for structured models.
11. **Subscription state = payment state** — Stripe model. One field to check.
12. **Pending upgrades** — don't apply until payment confirms.
13. **Deferred downgrades** — applied at cycle end.
14. **No pending invoices** — created at cycle end only. Immutable once finalized.
15. **Budget enforcement is app-layer** — library tracks, app enforces.
