<?php

declare(strict_types=1);

namespace Utopia\Billing;

use Utopia\Database\Document;

/**
 * Discount
 *
 * Document wrapper for the discounts collection. A discount is an applied
 * instance of a coupon on a specific subscription. It tracks the cycle
 * countdown for repeating discounts (Lago pattern).
 *
 * Duration behavior:
 * - once: cyclesRemaining 1 -> 0 -> status: exhausted
 * - repeating(N): cyclesRemaining N -> N-1 -> ... -> 0 -> status: exhausted
 * - forever: cyclesRemaining null, applied every cycle until admin cancels
 *
 * Scope determines discount level:
 * - scope: null -> invoice-level (applied to subtotal)
 * - scope: { resources: ['bandwidth'] } -> line-level (only matching items)
 */
class Discount
{
    /**
     * Discount type constants (inherited from coupon).
     */
    public const TYPE_FIXED = 'fixed';
    public const TYPE_PERCENTAGE = 'percentage';

    /**
     * Discount duration constants (inherited from coupon).
     */
    public const DURATION_ONCE = 'once';
    public const DURATION_REPEATING = 'repeating';
    public const DURATION_FOREVER = 'forever';

    /**
     * Discount status constants.
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Discount constructor.
     *
     * @param Document $document The underlying database document
     */
    public function __construct(protected Document $document)
    {
    }

    /**
     * Get the underlying database document.
     *
     * @return Document
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * Get the collection name for discounts.
     *
     * @return string
     */
    public static function getName(): string
    {
        return 'discounts';
    }

    /**
     * Get the discount ID.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->document->getId();
    }

    /**
     * Get the coupon ID this discount was created from.
     *
     * @return string
     */
    public function getCouponId(): string
    {
        return $this->document->getAttribute('couponId', '');
    }

    /**
     * Set the coupon ID.
     *
     * @param string $couponId
     *
     * @return self
     */
    public function setCouponId(string $couponId): self
    {
        $this->document->setAttribute('couponId', $couponId);

        return $this;
    }

    /**
     * Get the subscription ID this discount is applied to.
     *
     * @return string
     */
    public function getSubscriptionId(): string
    {
        return $this->document->getAttribute('subscriptionId', '');
    }

    /**
     * Set the subscription ID.
     *
     * @param string $subscriptionId
     *
     * @return self
     */
    public function setSubscriptionId(string $subscriptionId): self
    {
        $this->document->setAttribute('subscriptionId', $subscriptionId);

        return $this;
    }

    /**
     * Get the entity ID that owns this discount.
     *
     * @return string
     */
    public function getEntityId(): string
    {
        return $this->document->getAttribute('entityId', '');
    }

    /**
     * Set the entity ID.
     *
     * @param string $entityId
     *
     * @return self
     */
    public function setEntityId(string $entityId): self
    {
        $this->document->setAttribute('entityId', $entityId);

        return $this;
    }

    /**
     * Get the discount type ('fixed' or 'percentage'), inherited from the coupon.
     *
     * @return string One of TYPE_* constants
     */
    public function getType(): string
    {
        return $this->document->getAttribute('type', '');
    }

    /**
     * Set the discount type.
     *
     * @param string $type One of TYPE_* constants
     *
     * @return self
     */
    public function setType(string $type): self
    {
        $this->document->setAttribute('type', $type);

        return $this;
    }

    /**
     * Get the discount value (dollar amount for fixed, percentage for percentage type).
     *
     * @return float
     */
    public function getValue(): float
    {
        return $this->document->getAttribute('value', 0.0);
    }

    /**
     * Set the discount value.
     *
     * @param float $value Dollar amount for fixed type, percentage (e.g., 50.0) for percentage type
     *
     * @return self
     */
    public function setValue(float $value): self
    {
        $this->document->setAttribute('value', $value);

        return $this;
    }

    /**
     * Get the discount duration.
     *
     * @return string One of DURATION_* constants
     */
    public function getDuration(): string
    {
        return $this->document->getAttribute('duration', '');
    }

    /**
     * Set the discount duration.
     *
     * @param string $duration One of DURATION_* constants
     *
     * @return self
     */
    public function setDuration(string $duration): self
    {
        $this->document->setAttribute('duration', $duration);

        return $this;
    }

    /**
     * Get the discount scope.
     *
     * Null means invoice-level (applied to subtotal).
     * An array with 'resources' key means line-level (only matching items).
     *
     * @return array<string, mixed>|null
     */
    public function getScope(): ?array
    {
        return $this->document->getAttribute('scope');
    }

    /**
     * Set the discount scope.
     *
     * @param array<string, mixed>|null $scope Null for invoice-level, array for line-level
     *
     * @return self
     */
    public function setScope(?array $scope): self
    {
        $this->document->setAttribute('scope', $scope);

        return $this;
    }

    /**
     * Get the original total number of cycles (null for forever).
     *
     * @return int|null
     */
    public function getCyclesTotal(): ?int
    {
        return $this->document->getAttribute('cyclesTotal');
    }

    /**
     * Set the original total number of cycles.
     *
     * @param int|null $cyclesTotal Null for forever duration
     *
     * @return self
     */
    public function setCyclesTotal(?int $cyclesTotal): self
    {
        $this->document->setAttribute('cyclesTotal', $cyclesTotal);

        return $this;
    }

    /**
     * Get the remaining number of cycles (null for forever).
     *
     * Decremented each billing cycle until 0, at which point status becomes exhausted.
     *
     * @return int|null
     */
    public function getCyclesRemaining(): ?int
    {
        return $this->document->getAttribute('cyclesRemaining');
    }

    /**
     * Set the remaining number of cycles.
     *
     * @param int|null $cyclesRemaining Null for forever duration
     *
     * @return self
     */
    public function setCyclesRemaining(?int $cyclesRemaining): self
    {
        $this->document->setAttribute('cyclesRemaining', $cyclesRemaining);

        return $this;
    }

    /**
     * Get the discount status.
     *
     * @return string One of STATUS_* constants
     */
    public function getStatus(): string
    {
        return $this->document->getAttribute('status', '');
    }

    /**
     * Set the discount status.
     *
     * @param string $status One of STATUS_* constants
     *
     * @return self
     */
    public function setStatus(string $status): self
    {
        $this->document->setAttribute('status', $status);

        return $this;
    }

    /**
     * Get the datetime when the discount was applied.
     *
     * @return string
     */
    public function getAppliedAt(): string
    {
        return $this->document->getAttribute('appliedAt', '');
    }

    /**
     * Set the datetime when the discount was applied.
     *
     * @param string $appliedAt ISO 8601 datetime string
     *
     * @return self
     */
    public function setAppliedAt(string $appliedAt): self
    {
        $this->document->setAttribute('appliedAt', $appliedAt);

        return $this;
    }

    /**
     * Get the datetime when the discount was exhausted.
     *
     * @return string|null
     */
    public function getExhaustedAt(): ?string
    {
        return $this->document->getAttribute('exhaustedAt');
    }

    /**
     * Set the datetime when the discount was exhausted.
     *
     * @param string|null $exhaustedAt ISO 8601 datetime string or null
     *
     * @return self
     */
    public function setExhaustedAt(?string $exhaustedAt): self
    {
        $this->document->setAttribute('exhaustedAt', $exhaustedAt);

        return $this;
    }

    /**
     * Get the datetime when the discount was cancelled.
     *
     * @return string|null
     */
    public function getCancelledAt(): ?string
    {
        return $this->document->getAttribute('cancelledAt');
    }

    /**
     * Set the datetime when the discount was cancelled.
     *
     * @param string|null $cancelledAt ISO 8601 datetime string or null
     *
     * @return self
     */
    public function setCancelledAt(?string $cancelledAt): self
    {
        $this->document->setAttribute('cancelledAt', $cancelledAt);

        return $this;
    }

    /**
     * Get the discount metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->document->getAttribute('metadata', []);
    }

    /**
     * Set the discount metadata.
     *
     * @param array<string, mixed> $metadata
     *
     * @return self
     */
    public function setMetadata(array $metadata): self
    {
        $this->document->setAttribute('metadata', $metadata);

        return $this;
    }

    /**
     * Check if this discount is currently active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->getStatus() === self::STATUS_ACTIVE;
    }

    /**
     * Check if this discount has been exhausted (all cycles used).
     *
     * @return bool
     */
    public function isExhausted(): bool
    {
        return $this->getStatus() === self::STATUS_EXHAUSTED;
    }

    /**
     * Check if this discount has been cancelled.
     *
     * @return bool
     */
    public function isCancelled(): bool
    {
        return $this->getStatus() === self::STATUS_CANCELLED;
    }

    /**
     * Check if this is an invoice-level discount (scope is null).
     *
     * @return bool
     */
    public function isInvoiceLevel(): bool
    {
        return $this->getScope() === null;
    }

    /**
     * Check if this is a line-level discount (scope has resources).
     *
     * @return bool
     */
    public function isLineLevel(): bool
    {
        return $this->getScope() !== null;
    }

    /**
     * Get the resource keys this discount applies to (for line-level discounts).
     *
     * @return array<string> List of resource keys, empty if invoice-level
     */
    public function getScopeResources(): array
    {
        $scope = $this->getScope();

        if ($scope === null) {
            return [];
        }

        return $scope['resources'] ?? [];
    }

    /**
     * Check if this is a fixed-type discount.
     *
     * @return bool
     */
    public function isFixed(): bool
    {
        return $this->getType() === self::TYPE_FIXED;
    }

    /**
     * Check if this is a percentage-type discount.
     *
     * @return bool
     */
    public function isPercentage(): bool
    {
        return $this->getType() === self::TYPE_PERCENTAGE;
    }
}
