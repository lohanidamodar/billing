<?php

declare(strict_types=1);

namespace Utopia\Billing;

use Utopia\Database\Document;

/**
 * Coupon
 *
 * Document wrapper for the coupons collection. A coupon is a reusable
 * template/definition for discounts. It does not track application state;
 * that is handled by the Discount model.
 */
class Coupon
{
    /**
     * Coupon type constants.
     */
    public const TYPE_FIXED = 'fixed';
    public const TYPE_PERCENTAGE = 'percentage';

    /**
     * Coupon duration constants.
     */
    public const DURATION_ONCE = 'once';
    public const DURATION_REPEATING = 'repeating';
    public const DURATION_FOREVER = 'forever';

    /**
     * Coupon constructor.
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
     * Get the collection name for coupons.
     *
     * @return string
     */
    public static function getName(): string
    {
        return 'coupons';
    }

    /**
     * Get the coupon ID.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->document->getId();
    }

    /**
     * Get the user-facing coupon code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->document->getAttribute('code', '');
    }

    /**
     * Set the user-facing coupon code.
     *
     * @param string $code
     *
     * @return self
     */
    public function setCode(string $code): self
    {
        $this->document->setAttribute('code', $code);

        return $this;
    }

    /**
     * Get the coupon type ('fixed' or 'percentage').
     *
     * @return string One of TYPE_* constants
     */
    public function getType(): string
    {
        return $this->document->getAttribute('type', '');
    }

    /**
     * Set the coupon type.
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
     * Get the coupon value (dollar amount for fixed, percentage for percentage type).
     *
     * @return float
     */
    public function getValue(): float
    {
        return $this->document->getAttribute('value', 0.0);
    }

    /**
     * Set the coupon value.
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
     * Get the currency for fixed-type coupons.
     *
     * @return string|null
     */
    public function getCurrency(): ?string
    {
        return $this->document->getAttribute('currency');
    }

    /**
     * Set the currency for fixed-type coupons.
     *
     * @param string|null $currency ISO 4217 currency code or null
     *
     * @return self
     */
    public function setCurrency(?string $currency): self
    {
        $this->document->setAttribute('currency', $currency);

        return $this;
    }

    /**
     * Get the coupon duration.
     *
     * @return string One of DURATION_* constants
     */
    public function getDuration(): string
    {
        return $this->document->getAttribute('duration', '');
    }

    /**
     * Set the coupon duration.
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
     * Get the number of billing cycles for 'repeating' duration coupons.
     *
     * @return int|null Null for 'once' and 'forever' durations
     */
    public function getDurationInCycles(): ?int
    {
        return $this->document->getAttribute('durationInCycles');
    }

    /**
     * Set the number of billing cycles for 'repeating' duration coupons.
     *
     * @param int|null $durationInCycles
     *
     * @return self
     */
    public function setDurationInCycles(?int $durationInCycles): self
    {
        $this->document->setAttribute('durationInCycles', $durationInCycles);

        return $this;
    }

    /**
     * Get the maximum number of total redemptions allowed.
     *
     * @return int|null Null means unlimited
     */
    public function getMaxRedemptions(): ?int
    {
        return $this->document->getAttribute('maxRedemptions');
    }

    /**
     * Set the maximum number of total redemptions allowed.
     *
     * @param int|null $maxRedemptions Null for unlimited
     *
     * @return self
     */
    public function setMaxRedemptions(?int $maxRedemptions): self
    {
        $this->document->setAttribute('maxRedemptions', $maxRedemptions);

        return $this;
    }

    /**
     * Get the number of times this coupon has been redeemed.
     *
     * @return int
     */
    public function getTimesRedeemed(): int
    {
        return $this->document->getAttribute('timesRedeemed', 0);
    }

    /**
     * Set the number of times this coupon has been redeemed.
     *
     * @param int $timesRedeemed
     *
     * @return self
     */
    public function setTimesRedeemed(int $timesRedeemed): self
    {
        $this->document->setAttribute('timesRedeemed', $timesRedeemed);

        return $this;
    }

    /**
     * Get the coupon template expiry datetime.
     *
     * @return string|null
     */
    public function getExpiresAt(): ?string
    {
        return $this->document->getAttribute('expiresAt');
    }

    /**
     * Set the coupon template expiry datetime.
     *
     * @param string|null $expiresAt ISO 8601 datetime string or null
     *
     * @return self
     */
    public function setExpiresAt(?string $expiresAt): self
    {
        $this->document->setAttribute('expiresAt', $expiresAt);

        return $this;
    }

    /**
     * Get the coupon scope (plan IDs, resource types it applies to).
     *
     * @return array<string, mixed>|null Null means applies to everything
     */
    public function getScope(): ?array
    {
        return $this->document->getAttribute('scope');
    }

    /**
     * Set the coupon scope.
     *
     * @param array<string, mixed>|null $scope Null means applies to everything
     *
     * @return self
     */
    public function setScope(?array $scope): self
    {
        $this->document->setAttribute('scope', $scope);

        return $this;
    }

    /**
     * Check if the coupon is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->document->getAttribute('active', false);
    }

    /**
     * Set the coupon active status.
     *
     * @param bool $active
     *
     * @return self
     */
    public function setActive(bool $active): self
    {
        $this->document->setAttribute('active', $active);

        return $this;
    }

    /**
     * Get the coupon metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->document->getAttribute('metadata', []);
    }

    /**
     * Set the coupon metadata.
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
     * Check if the coupon can still be redeemed (active, not expired, under max redemptions).
     *
     * @return bool
     */
    public function isRedeemable(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $expiresAt = $this->getExpiresAt();
        if ($expiresAt !== null && new \DateTime($expiresAt) < new \DateTime()) {
            return false;
        }

        $maxRedemptions = $this->getMaxRedemptions();
        if ($maxRedemptions !== null && $this->getTimesRedeemed() >= $maxRedemptions) {
            return false;
        }

        return true;
    }

    /**
     * Check if this is a fixed-type coupon.
     *
     * @return bool
     */
    public function isFixed(): bool
    {
        return $this->getType() === self::TYPE_FIXED;
    }

    /**
     * Check if this is a percentage-type coupon.
     *
     * @return bool
     */
    public function isPercentage(): bool
    {
        return $this->getType() === self::TYPE_PERCENTAGE;
    }
}
