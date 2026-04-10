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
     * Subscription constructor.
     *
     * @param  Document  $document  The underlying database document
     */
    public function __construct(protected Document $document) {}

    /**
     * Get the underlying database document.
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * Get the collection name for subscriptions.
     */
    public static function getName(): string
    {
        return 'subscriptions';
    }

    /**
     * Get the subscription ID.
     */
    public function getId(): string
    {
        return $this->document->getId();
    }

    /**
     * Get the entity ID (team, user, org) that owns this subscription.
     */
    public function getEntityId(): string
    {
        return (string) $this->document->getAttribute('entityId', '');
    }

    /**
     * Set the entity ID.
     */
    public function setEntityId(string $entityId): self
    {
        $this->document->setAttribute('entityId', $entityId);

        return $this;
    }

    /**
     * Get the entity type (e.g., 'organization', 'user').
     */
    public function getEntityType(): string
    {
        return (string) $this->document->getAttribute('entityType', '');
    }

    /**
     * Set the entity type.
     */
    public function setEntityType(string $entityType): self
    {
        $this->document->setAttribute('entityType', $entityType);

        return $this;
    }

    /**
     * Get the current active plan ID.
     */
    public function getPlanId(): string
    {
        return (string) $this->document->getAttribute('planId', '');
    }

    /**
     * Set the plan ID.
     */
    public function setPlanId(string $planId): self
    {
        $this->document->setAttribute('planId', $planId);

        return $this;
    }

    /**
     * Get the subscription status.
     */
    public function getStatus(): SubscriptionStatus
    {
        return SubscriptionStatus::from((string) $this->document->getAttribute('status', 'incomplete'));
    }

    /**
     * Set the subscription status.
     */
    public function setStatus(SubscriptionStatus $status): self
    {
        $this->document->setAttribute('status', $status->value);

        return $this;
    }

    /**
     * Get the current billing period start date.
     */
    public function getCurrentPeriodStart(): string
    {
        return (string) $this->document->getAttribute('currentPeriodStart', '');
    }

    /**
     * Set the current billing period start date.
     *
     * @param  string  $currentPeriodStart  ISO 8601 datetime string
     */
    public function setCurrentPeriodStart(string $currentPeriodStart): self
    {
        $this->document->setAttribute('currentPeriodStart', $currentPeriodStart);

        return $this;
    }

    /**
     * Get the current billing period end date.
     */
    public function getCurrentPeriodEnd(): string
    {
        return (string) $this->document->getAttribute('currentPeriodEnd', '');
    }

    /**
     * Set the current billing period end date.
     *
     * @param  string  $currentPeriodEnd  ISO 8601 datetime string
     */
    public function setCurrentPeriodEnd(string $currentPeriodEnd): self
    {
        $this->document->setAttribute('currentPeriodEnd', $currentPeriodEnd);

        return $this;
    }

    /**
     * Get the trial start date.
     */
    public function getTrialStart(): ?string
    {
        return $this->document->getAttribute('trialStart');
    }

    /**
     * Set the trial start date.
     *
     * @param  string|null  $trialStart  ISO 8601 datetime string or null
     */
    public function setTrialStart(?string $trialStart): self
    {
        $this->document->setAttribute('trialStart', $trialStart);

        return $this;
    }

    /**
     * Get the trial end date.
     */
    public function getTrialEnd(): ?string
    {
        return $this->document->getAttribute('trialEnd');
    }

    /**
     * Set the trial end date.
     *
     * @param  string|null  $trialEnd  ISO 8601 datetime string or null
     */
    public function setTrialEnd(?string $trialEnd): self
    {
        $this->document->setAttribute('trialEnd', $trialEnd);

        return $this;
    }

    /**
     * Get the pending plan ID (requested upgrade or downgrade).
     */
    public function getPendingPlanId(): ?string
    {
        return $this->document->getAttribute('pendingPlanId');
    }

    /**
     * Set the pending plan ID.
     */
    public function setPendingPlanId(?string $pendingPlanId): self
    {
        $this->document->setAttribute('pendingPlanId', $pendingPlanId);

        return $this;
    }

    /**
     * Get the pending change type.
     */
    public function getPendingChangeType(): ?ChangeType
    {
        $value = $this->document->getAttribute('pendingChangeType');

        return $value !== null ? ChangeType::from((string) $value) : null;
    }

    /**
     * Set the pending change type.
     */
    public function setPendingChangeType(?ChangeType $pendingChangeType): self
    {
        $this->document->setAttribute('pendingChangeType', $pendingChangeType?->value);

        return $this;
    }

    /**
     * Get the datetime when the pending change was requested.
     */
    public function getPendingChangedAt(): ?string
    {
        return $this->document->getAttribute('pendingChangedAt');
    }

    /**
     * Set the datetime when the pending change was requested.
     *
     * @param  string|null  $pendingChangedAt  ISO 8601 datetime string or null
     */
    public function setPendingChangedAt(?string $pendingChangedAt): self
    {
        $this->document->setAttribute('pendingChangedAt', $pendingChangedAt);

        return $this;
    }

    /**
     * Get the auto-expiry datetime for pending upgrades.
     */
    public function getPendingExpiresAt(): ?string
    {
        return $this->document->getAttribute('pendingExpiresAt');
    }

    /**
     * Set the auto-expiry datetime for pending upgrades.
     *
     * @param  string|null  $pendingExpiresAt  ISO 8601 datetime string or null
     */
    public function setPendingExpiresAt(?string $pendingExpiresAt): self
    {
        $this->document->setAttribute('pendingExpiresAt', $pendingExpiresAt);

        return $this;
    }

    /**
     * Get the invoice ID for the pending upgrade payment.
     */
    public function getPendingInvoiceId(): ?string
    {
        return $this->document->getAttribute('pendingInvoiceId');
    }

    /**
     * Set the invoice ID for the pending upgrade payment.
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
     * @param  float|null  $budget  Dollar cap per cycle, null for unlimited
     */
    public function setBudget(?float $budget): self
    {
        $this->document->setAttribute('budget', $budget);

        return $this;
    }

    /**
     * Get the current cycle usage total.
     */
    public function getBudgetUsed(): float
    {
        return (float) $this->document->getAttribute('budgetUsed', 0.0);
    }

    /**
     * Set the current cycle usage total.
     */
    public function setBudgetUsed(float $budgetUsed): self
    {
        $this->document->setAttribute('budgetUsed', $budgetUsed);

        return $this;
    }

    /**
     * Check if the budget limit has been reached.
     */
    public function getBudgetLimitReached(): bool
    {
        return (bool) $this->document->getAttribute('budgetLimitReached', false);
    }

    /**
     * Set whether the budget limit has been reached.
     */
    public function setBudgetLimitReached(bool $budgetLimitReached): self
    {
        $this->document->setAttribute('budgetLimitReached', $budgetLimitReached);

        return $this;
    }

    /**
     * Get the number of consecutive failed payment attempts.
     */
    public function getFailedPaymentAttempts(): int
    {
        return (int) $this->document->getAttribute('failedPaymentAttempts', 0);
    }

    /**
     * Set the number of consecutive failed payment attempts.
     */
    public function setFailedPaymentAttempts(int $failedPaymentAttempts): self
    {
        $this->document->setAttribute('failedPaymentAttempts', $failedPaymentAttempts);

        return $this;
    }

    /**
     * Get the next payment retry datetime.
     */
    public function getNextRetryAt(): ?string
    {
        return $this->document->getAttribute('nextRetryAt');
    }

    /**
     * Set the next payment retry datetime.
     *
     * @param  string|null  $nextRetryAt  ISO 8601 datetime string or null
     */
    public function setNextRetryAt(?string $nextRetryAt): self
    {
        $this->document->setAttribute('nextRetryAt', $nextRetryAt);

        return $this;
    }

    /**
     * Get the datetime of the last failed payment attempt.
     */
    public function getLastFailedAt(): ?string
    {
        return $this->document->getAttribute('lastFailedAt');
    }

    /**
     * Set the datetime of the last failed payment attempt.
     *
     * @param  string|null  $lastFailedAt  ISO 8601 datetime string or null
     */
    public function setLastFailedAt(?string $lastFailedAt): self
    {
        $this->document->setAttribute('lastFailedAt', $lastFailedAt);

        return $this;
    }

    /**
     * Check if the subscription is set to cancel at the end of the current period.
     */
    public function getCancelAtPeriodEnd(): bool
    {
        return (bool) $this->document->getAttribute('cancelAtPeriodEnd', false);
    }

    /**
     * Set whether the subscription should cancel at the end of the current period.
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
        $meta = $this->document->getAttribute('metadata', []);

        if (\is_string($meta)) {
            return (array) \json_decode($meta, true);
        }

        return $meta;
    }

    /**
     * Set the subscription metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->document->setAttribute('metadata', \json_encode($metadata));

        return $this;
    }

    /**
     * Check if the subscription has a pending plan change.
     */
    public function hasPendingChange(): bool
    {
        return $this->getPendingPlanId() !== null;
    }

    /**
     * Check if the subscription has an active or trialing status (i.e., access is granted).
     */
    public function isAccessible(): bool
    {
        return $this->getStatus()->isAccessible();
    }

    /**
     * Check if the subscription is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this->getStatus()->isTerminal();
    }

    /**
     * Get the current billing period as a Period value object.
     *
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
