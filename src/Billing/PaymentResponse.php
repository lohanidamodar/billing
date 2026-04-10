<?php

declare(strict_types=1);

namespace Utopia\Billing;

/**
 * PaymentResponse
 *
 * Value object returned by Payment adapters representing the result
 * of a charge or refund operation.
 *
 * Possible statuses:
 * - succeeded: Payment completed successfully
 * - requires_action: 3DS/SCA needed — clientSecret provided for frontend confirmation
 * - processing: Payment is processing asynchronously (bank transfer, SEPA)
 * - failed: Payment failed — errorCode/errorMessage provided
 */
class PaymentResponse
{
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_REQUIRES_ACTION = 'requires_action';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?string $providerPaymentId = null,
        public readonly ?string $clientSecret = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function requiresAction(): bool
    {
        return $this->status === self::STATUS_REQUIRES_ACTION;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isPending(): bool
    {
        return $this->requiresAction() || $this->isProcessing();
    }
}
