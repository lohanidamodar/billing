<?php

declare(strict_types=1);

namespace Utopia\Billing;

/**
 * Payment
 *
 * Abstract payment adapter defining the contract for payment gateway
 * integrations. Concrete implementations (Pay, Manual) provide the
 * actual charge/refund logic.
 *
 * Follows the same Adapter pattern used throughout utopia-php libraries
 * (database, messaging, storage, etc.).
 */
abstract class Payment
{
    /**
     * Get the adapter name.
     */
    abstract public function getName(): string;

    /**
     * Charge a payment.
     *
     * @param  float  $amount  Amount in standard currency units (e.g., 15.99)
     * @param  string  $currency  ISO 4217 currency code (e.g., 'USD')
     * @param  string  $customerId  The customer ID at the payment provider
     * @param  string|null  $paymentMethodId  The payment method to charge (null for default)
     * @param  array<string, mixed>  $metadata  Optional metadata to attach to the charge
     */
    abstract public function charge(
        float $amount,
        string $currency,
        string $customerId,
        ?string $paymentMethodId = null,
        array $metadata = [],
    ): PaymentResponse;

    /**
     * Refund a payment.
     *
     * @param  string  $providerPaymentId  The provider's payment ID to refund
     * @param  float|null  $amount  Amount to refund (null for full refund)
     * @param  string|null  $reason  Reason for the refund
     */
    abstract public function refund(
        string $providerPaymentId,
        ?float $amount = null,
        ?string $reason = null,
    ): PaymentResponse;
}
