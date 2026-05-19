<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;
use Utopia\Billing\ChangeType;
use Utopia\Billing\Subscription;
use Utopia\Billing\SubscriptionStatus;
use Utopia\Database\Document;

class SubscriptionTest extends TestCase
{
    public function testSubscriptionModel(): void
    {
        $doc = new Document([
            '$id' => 'sub-1',
            'entityId' => 'entity-1',
            'entityType' => 'organization',
            'planId' => 'plan-pro',
            'status' => 'active',
            'currentPeriodStart' => '2026-04-01T00:00:00.000+00:00',
            'currentPeriodEnd' => '2026-05-01T00:00:00.000+00:00',
            'cancelAtPeriodEnd' => false,
            'budgetUsed' => 0.0,
            'budgetLimitReached' => false,
            'failedPaymentAttempts' => 0,
            'metadata' => [],
        ]);

        $sub = new Subscription($doc);

        $this->assertEquals('sub-1', $sub->getId());
        $this->assertTrue($sub->isAccessible());
        $this->assertFalse($sub->isTerminal());
        $this->assertFalse($sub->hasPendingChange());
        $this->assertEquals('subscriptions', Subscription::getName());
    }

    public function testSubscriptionAllStatusesAccessibleAndTerminal(): void
    {
        $doc = new Document(['$id' => 'sub-test', 'metadata' => []]);
        $sub = new Subscription($doc);

        // Test each status
        foreach (SubscriptionStatus::cases() as $status) {
            $sub->setStatus($status);
            $this->assertEquals($status, $sub->getStatus());
            $this->assertEquals($status->isAccessible(), $sub->isAccessible());
            $this->assertEquals($status->isTerminal(), $sub->isTerminal());
        }
    }

    public function testSubscriptionAllSettersGetters(): void
    {
        $doc = new Document(['$id' => 'sub-cov', 'metadata' => '{}']);
        $sub = new Subscription($doc);

        $sub->setEntityId('ent-1');
        $this->assertEquals('ent-1', $sub->getEntityId());

        $sub->setEntityType('user');
        $this->assertEquals('user', $sub->getEntityType());

        $sub->setPlanId('plan-x');
        $this->assertEquals('plan-x', $sub->getPlanId());

        $sub->setCurrentPeriodStart('2026-04-01T00:00:00.000+00:00');
        $this->assertEquals('2026-04-01T00:00:00.000+00:00', $sub->getCurrentPeriodStart());

        $sub->setCurrentPeriodEnd('2026-05-01T00:00:00.000+00:00');
        $this->assertEquals('2026-05-01T00:00:00.000+00:00', $sub->getCurrentPeriodEnd());

        $sub->setTrialStart('2026-04-01T00:00:00.000+00:00');
        $this->assertEquals('2026-04-01T00:00:00.000+00:00', $sub->getTrialStart());

        $sub->setTrialEnd('2026-04-15T00:00:00.000+00:00');
        $this->assertEquals('2026-04-15T00:00:00.000+00:00', $sub->getTrialEnd());

        $sub->setPendingPlanId('plan-y');
        $this->assertEquals('plan-y', $sub->getPendingPlanId());

        $sub->setPendingChangeType(ChangeType::Upgrade);
        $this->assertEquals(ChangeType::Upgrade, $sub->getPendingChangeType());
        $sub->setPendingChangeType(null);
        $this->assertNull($sub->getPendingChangeType());

        $sub->setPendingChangedAt('2026-04-05T00:00:00.000+00:00');
        $this->assertEquals('2026-04-05T00:00:00.000+00:00', $sub->getPendingChangedAt());

        $sub->setPendingExpiresAt('2026-04-06T00:00:00.000+00:00');
        $this->assertEquals('2026-04-06T00:00:00.000+00:00', $sub->getPendingExpiresAt());

        $sub->setPendingInvoiceId('inv-pending');
        $this->assertEquals('inv-pending', $sub->getPendingInvoiceId());

        $sub->setBudget(50.0);
        $this->assertEquals(50.0, $sub->getBudget());

        $sub->setBudgetUsed(25.0);
        $this->assertEquals(25.0, $sub->getBudgetUsed());

        $sub->setBudgetLimitReached(true);
        $this->assertTrue($sub->getBudgetLimitReached());

        $sub->setFailedPaymentAttempts(3);
        $this->assertEquals(3, $sub->getFailedPaymentAttempts());

        $sub->setNextRetryAt('2026-04-12T00:00:00.000+00:00');
        $this->assertEquals('2026-04-12T00:00:00.000+00:00', $sub->getNextRetryAt());

        $sub->setLastFailedAt('2026-04-11T00:00:00.000+00:00');
        $this->assertEquals('2026-04-11T00:00:00.000+00:00', $sub->getLastFailedAt());

        $sub->setCancelAtPeriodEnd(true);
        $this->assertTrue($sub->getCancelAtPeriodEnd());

        $sub->setMetadata(['key' => 'val']);
        $this->assertEquals(['key' => 'val'], $sub->getMetadata());

        $this->assertInstanceOf(Document::class, $sub->getDocument());
    }
}
