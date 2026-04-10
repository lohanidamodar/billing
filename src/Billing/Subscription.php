<?php

declare(strict_types=1);

namespace Utopia\Billing;

use DateTime;
use Utopia\Database\Document;

/**
 * Subscription
 *
 * Document wrapper for the subscriptions collection. Represents a billing
 * subscription with status tracking, billing periods, trial management,
 * pending plan changes (upgrades/downgrades), budget caps, and dunning state.
 */
class Subscription
{
    /**
     * Subscription status constants.
     *
     * @see CLAUDE.md "Subscription State Machine" for transitions.
     */
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_INCOMPLETE_EXPIRED = 'incomplete_expired';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELING = 'canceling';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Pending change type constants.
     */
    public const CHANGE_TYPE_UPGRADE = 'upgrade';
    public const CHANGE_TYPE_DOWNGRADE = 'downgrade';

    /**
     * Subscription constructor.
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
     * Get the collection name for subscriptions.
     *
     * @return string
     */
    public static function getName(): string
    {
        return 'subscriptions';
    }

    /**
     * Get the subscription ID.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->document->getId();
    }

    /**
     * Get the entity ID (team, user, org) that owns this subscription.
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
     * Get the entity type (e.g., 'organization', 'user').
     *
     * @return string
     */
    public function getEntityType(): string
    {
        return $this->document->getAttribute('entityType', '');
    }

    /**
     * Set the entity type.
     *
     * @param string $entityType
     *
     * @return self
     */
    public function setEntityType(string $entityType): self
    {
        $this->document->setAttribute('entityType', $entityType);

        return $this;
    }

    /**
     * Get the current active plan ID.
     *
     * @return string
     */
    public function getPlanId(): string
    {
        return $this->document->getAttribute('planId', '');
    }

    /**
     * Set the plan ID.
     *
     * @param string $planId
     *
     * @return self
     */
    public function setPlanId(string $planId): self
    {
        $this->document->setAttribute('planId', $planId);

        return $this;
    }

    /**
     * Get the subscription status.
     *
     * @return string One of the STATUS_* constants
     */
    public function getStatus(): string
    {
        return $this->document->getAttribute('status', '');
    }

    /**
     * Set the subscription status.
     *
     * @param string $status One of the STATUS_* constants
     *
     * @return self
     */
    public function setStatus(string $status): self
    {
        $this->document->setAttribute('status', $status);

        return $this;
    }

    /**
     * Get the current billing period start date.
     *
     * @return string
     */
    public function getCurrentPeriodStart(): string
    {
        return $this->document->getAttribute('currentPeriodStart', '');
    }

    /**
     * Set the current billing period start date.
     *
     * @param string $currentPeriodStart ISO 8601 datetime string
     *
     * @return self
     */
    public function setCurrentPeriodStart(string $currentPeriodStart): self
    {
        $this->document->setAttribute('currentPeriodStart', $currentPeriodStart);

        return $this;
    }

    /**
     * Get the current billing period end date.
     *
     * @return string
     */
    public function getCurrentPeriodEnd(): string
    {
        return $this->document->getAttribute('currentPeriodEnd', '');
    }

    /**
     * Set the current billing period end date.
     *
     * @param string $currentPeriodEnd ISO 8601 datetime string
     *
     * @return self
     */
    public function setCurrentPeriodEnd(string $currentPeriodEnd): self
    {
        $this->document->setAttribute('currentPeriodEnd', $currentPeriodEnd);

        return $this;
    }

    /**
     * Get the trial start date.
     *
     * @return string|null
     */
    public function getTrialStart(): ?string
    {
        return $this->document->getAttribute('trialStart');
    }

    /**
     * Set the trial start date.
     *
     * @param string|null $trialStart ISO 8601 datetime string or null
     *
     * @return self
     */
    public function setTrialStart(?string $trialStart): self
    {
        $this->document->setAttribute('trialStart', $trialStart);

        return $this;
    }

    /**
     * Get the trial end date.
     *
     * @return string|null
     */
    public function getTrialEnd(): ?string
    {
        return $this->document->getAttribute('trialEnd');
    }

    /**
     * Set the trial end date.
     *
     * @param string|null $trialEnd ISO 8601 datetime string or null
     *
     * @return self
     */
    public function setTrialEnd(?string $trialEnd): self
    {
        $this->document->setAttribute('trialEnd', $trialEnd);

        return $this;
    }

    /**
     * Get the pending plan ID (requested upgrade or downgrade).
     *
     * @return string|null
     */
    public function getPendingPlanId(): ?string
    {
        return $this->document->getAttribute('pendingPlanId');
    }

    /**
     * Set the pending plan ID.
     *
     * @param string|null $pendingPlanId
     *
     * @return self
     */
    public function setPendingPlanId(?string $pendingPlanId): self
    {
        $this->document->setAttribute('pendingPlanId', $pendingPlanId);

        return $this;
    }

    /**
     * Get the pending change type ('upgrade' or 'downgrade').
     *
     * @return string|null One of CHANGE_TYPE_* constants or null
     */
    public function getPendingChangeType(): ?string
    {
        return $this->document->getAttribute('pendingChangeType');
    }

    /**
     * Set the pending change type.
     *
     * @param string|null $pendingChangeType One of CHANGE_TYPE_* constants or null
     *
     * @return self
     */
    public function setPendingChangeType(?string $pendingChangeType): self
    {
        $this->document->setAttribute('pendingChangeType', $pendingChangeType);

        return $this;
    }

    /**
     * Get the datetime when the pending change was requested.
     *
     * @return string|null
     */
    public function getPendingChangedAt(): ?string
    {
        return $this->document->getAttribute('pendingChangedAt');
    }

    /**
     * Set the datetime when the pending change was requested.
     *
     * @param string|null $pendingChangedAt ISO 8601 datetime string or null
     *
     * @return self
     */
    public function setPendingChangedAt(?string $pendingChangedAt): self
    {
        $this->document->setAttribute('pendingChangedAt', $pendingChangedAt);

        return $this;
    }

    /**
     * Get the auto-expiry datetime for pending upgrades.
     *
     * @return string|null
     */
    public function getPendingExpiresAt(): ?string
    {
        return $this->document->getAttribute('pendingExpiresAt');
    }

    /**
     * Set the auto-expiry datetime for pending upgrades.
     *
     * @param string|null $pendingExpiresAt ISO 8601 datetime string or null
     *
     * @return self
     */
    public function setPendingExpiresAt(?string $pendingExpiresAt): self
    {
        $this->document->setAttribute('pendingExpiresAt', $pendingExpiresAt);

        return $this;
    }

    /**
     * Get the invoice ID for the pending upgrade payment.
     *
     * @return string|null
     */
    public function getPendingInvoiceId(): ?string
    {
        return $this->document->getAttribute('pendingInvoiceId');
    }

    /**
     * Set the invoice ID for the pending upgrade payment.
     *
     * @param string|null $pendingInvoiceId
     *
     * @return self
     */
    public function setPendingInvoiceId(?string $pendingInvoiceId): self
    {
        $this->document->setAttribute('pendingInvoiceId', $pendingInvoiceId);

        return $this;
    }

    /**
     * Get the budget (spending cap) per billing cycle.
     *
     * @return float|null Null means unlimited
     */
    public function getBudget(): ?float
    {
        return $this->document->getAttribute('budget');
    }

    /**
     * Set the budget (spending cap) per billing cycle.
     *
     * @param float|null $budget Dollar cap per cycle, null for unlimited
     *
     * @return self
     */
    public function setBudget(?float $budget): self
    {
        $this->document->setAttribute('budget', $budget);

        return $this;
    }

    /**
     * Get the current cycle usage total.
     *
     * @return float
     */
    public function getBudgetUsed(): float
    {
        return $this->document->getAttribute('budgetUsed', 0.0);
    }

    /**
     * Set the current cycle usage total.
     *
     * @param float $budgetUsed
     *
     * @return self
     */
    public function setBudgetUsed(float $budgetUsed): self
    {
        $this->document->setAttribute('budgetUsed', $budgetUsed);

        return $this;
    }

    /**
     * Check if the budget limit has been reached.
     *
     * Computed as: budgetUsed >= budget (when budget is not null).
     *
     * @return bool
     */
    public function getBudgetLimitReached(): bool
    {
        return $this->document->getAttribute('budgetLimitReached', false);
    }

    /**
     * Set whether the budget limit has been reached.
     *
     * @param bool $budgetLimitReached
     *
     * @return self
     */
    public function setBudgetLimitReached(bool $budgetLimitReached): self
    {
        $this->document->setAttribute('budgetLimitReached', $budgetLimitReached);

        return $this;
    }

    /**
     * Get the number of consecutive failed payment attempts.
     *
     * @return int
     */
    public function getFailedPaymentAttempts(): int
    {
        return $this->document->getAttribute('failedPaymentAttempts', 0);
    }

    /**
     * Set the number of consecutive failed payment attempts.
     *
     * @param int $failedPaymentAttempts
     *
     * @return self
     */
    public function setFailedPaymentAttempts(int $failedPaymentAttempts): self
    {
        $this->document->setAttribute('failedPaymentAttempts', $failedPaymentAttempts);

        return $this;
    }

    /**
     * Get the next payment retry datetime.
     *
     * @return string|null
     */
    public function getNextRetryAt(): ?string
    {
        return $this->document->getAttribute('nextRetryAt');
    }

    /**
     * Set the next payment retry datetime.
     *
     * @param string|null $nextRetryAt ISO 8601 datetime string or null
     *
     * @return self
     */
    public function setNextRetryAt(?string $nextRetryAt): self
    {
        $this->document->setAttribute('nextRetryAt', $nextRetryAt);

        return $this;
    }

    /**
     * Get the datetime of the last failed payment attempt.
     *
     * @return string|null
     */
    public function getLastFailedAt(): ?string
    {
        return $this->document->getAttribute('lastFailedAt');
    }

    /**
     * Set the datetime of the last failed payment attempt.
     *
     * @param string|null $lastFailedAt ISO 8601 datetime string or null
     *
     * @return self
     */
    public function setLastFailedAt(?string $lastFailedAt): self
    {
        $this->document->setAttribute('lastFailedAt', $lastFailedAt);

        return $this;
    }

    /**
     * Check if the subscription is set to cancel at the end of the current period.
     *
     * @return bool
     */
    public function getCancelAtPeriodEnd(): bool
    {
        return $this->document->getAttribute('cancelAtPeriodEnd', false);
    }

    /**
     * Set whether the subscription should cancel at the end of the current period.
     *
     * @param bool $cancelAtPeriodEnd
     *
     * @return self
     */
    public function setCancelAtPeriodEnd(bool $cancelAtPeriodEnd): self
    {
        $this->document->setAttribute('cancelAtPeriodEnd', $cancelAtPeriodEnd);

        return $this;
    }

    /**
     * Get the subscription metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->document->getAttribute('metadata', []);
    }

    /**
     * Set the subscription metadata.
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
     * Check if the subscription has a pending plan change.
     *
     * @return bool
     */
    public function hasPendingChange(): bool
    {
        return $this->getPendingPlanId() !== null;
    }

    /**
     * Check if the subscription has an active or trialing status (i.e., access is granted).
     *
     * @return bool
     */
    public function isAccessible(): bool
    {
        return match ($this->getStatus()) {
            self::STATUS_ACTIVE,
            self::STATUS_TRIALING,
            self::STATUS_PAST_DUE,
            self::STATUS_CANCELING => true,
            default => false,
        };
    }

    /**
     * Check if the subscription is in a terminal state.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return match ($this->getStatus()) {
            self::STATUS_INCOMPLETE_EXPIRED,
            self::STATUS_CANCELED => true,
            default => false,
        };
    }

    /**
     * Get the current billing period as a Period value object.
     *
     * @return Period
     *
     * @throws Exception If period dates are not set
     */
    public function getCurrentPeriod(): Period
    {
        $start = $this->getCurrentPeriodStart();
        $end = $this->getCurrentPeriodEnd();

        if ($start === '' || $end === '') {
            throw new Exception('Subscription billing period dates are not set');
        }

        return new Period(
            new DateTime($start),
            new DateTime($end),
        );
    }
}
