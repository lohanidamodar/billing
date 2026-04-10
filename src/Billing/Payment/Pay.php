<?php

declare(strict_types=1);

namespace Utopia\Billing\Payment;

use Utopia\Billing\Payment;
use Utopia\Billing\PaymentResponse;
use Utopia\Pay\Currency;
use Utopia\Pay\Pay as PayFacade;
use Utopia\Pay\Payment\Payment as PayPayment;

/**
 * Pay Adapter
 *
 * Wraps utopia-php/pay (Stripe, etc.) to provide payment gateway
 * functionality to the Billing library. Uses the structured response
 * models from the Pay library.
 *
 * Requires utopia-php/pay (branch: claude/improve-utopia-library-EdGjh).
 */
class Pay extends Payment
{
    public function __construct(
        protected PayFacade $pay,
    ) {
    }

    public function getName(): string
    {
        return 'pay:' . $this->pay->getName();
    }

    /**
     * {@inheritDoc}
     */
    public function charge(
        float $amount,
        string $currency,
        string $customerId,
        ?string $paymentMethodId = null,
        array $metadata = [],
    ): PaymentResponse {
        $smallestUnit = Currency::toSmallestUnit($amount, strtoupper($currency));

        $this->pay->setCurrency(strtoupper($currency));

        $payment = $this->pay->purchase(
            $smallestUnit,
            $customerId,
            $paymentMethodId,
            ['metadata' => $metadata],
        );

        return $this->mapPayment($payment);
    }

    /**
     * {@inheritDoc}
     */
    public function refund(
        string $providerPaymentId,
        ?float $amount = null,
        ?string $reason = null,
    ): PaymentResponse {
        $smallestUnit = null;
        if ($amount !== null) {
            $currency = $this->pay->getCurrency();
            $smallestUnit = Currency::toSmallestUnit($amount, strtoupper($currency));
        }

        $refund = $this->pay->refund($providerPaymentId, $smallestUnit, $reason);

        return new PaymentResponse(
            status: $refund->isSucceeded() ? PaymentResponse::STATUS_SUCCEEDED : (
                $refund->isPending() ? PaymentResponse::STATUS_PROCESSING : PaymentResponse::STATUS_FAILED
            ),
            providerPaymentId: $refund->getPaymentId(),
            errorMessage: $refund->getFailureReason(),
        );
    }

    /**
     * Map a Pay\Payment to a PaymentResponse.
     */
    private function mapPayment(PayPayment $payment): PaymentResponse
    {
        $status = match ($payment->getStatus()) {
            PayPayment::STATUS_SUCCEEDED => PaymentResponse::STATUS_SUCCEEDED,
            PayPayment::STATUS_REQUIRES_ACTION,
            PayPayment::STATUS_REQUIRES_CONFIRMATION,
            PayPayment::STATUS_REQUIRES_PAYMENT_METHOD => PaymentResponse::STATUS_REQUIRES_ACTION,
            PayPayment::STATUS_PROCESSING,
            PayPayment::STATUS_REQUIRES_CAPTURE => PaymentResponse::STATUS_PROCESSING,
            default => PaymentResponse::STATUS_FAILED,
        };

        return new PaymentResponse(
            status: $status,
            providerPaymentId: $payment->getId(),
            clientSecret: $payment->getClientSecret(),
            errorCode: $payment->getFailureCode(),
            errorMessage: $payment->getFailureMessage(),
        );
    }
}
