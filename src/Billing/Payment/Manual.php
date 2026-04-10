<?php

declare(strict_types=1);

namespace Utopia\Billing\Payment;

use Utopia\Billing\Payment;
use Utopia\Billing\PaymentResponse;

/**
 * Manual Adapter
 *
 * A no-op payment adapter for wallet-only or offline billing.
 * Always returns succeeded — no actual gateway call is made.
 *
 * Use this when:
 * - Billing is wallet-only (all charges covered by prepaid balance)
 * - Payments are handled outside the system (bank transfer, cash)
 * - Testing without a real payment provider
 */
class Manual extends Payment
{
    public function getName(): string
    {
        return 'manual';
    }

    /**
     * {@inheritDoc}
     *
     * Always returns succeeded. The app is responsible for confirming
     * that the offline payment was actually received.
     */
    public function charge(
        float $amount,
        string $currency,
        string $customerId,
        ?string $paymentMethodId = null,
        array $metadata = [],
    ): PaymentResponse {
        return new PaymentResponse(
            status: PaymentResponse::STATUS_SUCCEEDED,
            providerPaymentId: 'manual_' . \bin2hex(\random_bytes(8)),
        );
    }

    /**
     * {@inheritDoc}
     *
     * Always returns succeeded. Actual refund handling is offline.
     */
    public function refund(
        string $providerPaymentId,
        ?float $amount = null,
        ?string $reason = null,
    ): PaymentResponse {
        return new PaymentResponse(
            status: PaymentResponse::STATUS_SUCCEEDED,
            providerPaymentId: $providerPaymentId,
        );
    }
}
