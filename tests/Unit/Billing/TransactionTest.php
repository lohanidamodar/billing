<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;
use Utopia\Billing\Transaction;
use Utopia\Billing\TransactionStatus;
use Utopia\Billing\TransactionType;
use Utopia\Database\Document;

class TransactionTest extends TestCase
{
    public function testTransactionModel(): void
    {
        $doc = new Document([
            '$id' => 'tx-1',
            'entityId' => 'entity-1',
            'invoiceId' => 'inv-1',
            'type' => 'gateway_charge',
            'amount' => 99.99,
            'status' => 'succeeded',
            'metadata' => [],
        ]);

        $tx = new Transaction($doc);

        $this->assertTrue($tx->isGatewayTransaction());
        $this->assertFalse($tx->isWalletTransaction());
        $this->assertFalse($tx->isCreditTransaction());
        $this->assertTrue($tx->isSucceeded());
        $this->assertFalse($tx->isFailed());
        $this->assertFalse($tx->isPending());
        $this->assertEquals('transactions', Transaction::getName());
    }

    public function testTransactionAllTypesClassification(): void
    {
        $doc = new Document(['$id' => 'tx-test', 'metadata' => []]);
        $tx = new Transaction($doc);

        foreach (TransactionType::cases() as $type) {
            $tx->setType($type);
            $this->assertEquals($type, $tx->getType());
            $this->assertEquals($type->isWallet(), $tx->isWalletTransaction());
            $this->assertEquals($type->isGateway(), $tx->isGatewayTransaction());
            $this->assertEquals($type->isCredit(), $tx->isCreditTransaction());
        }
    }

    public function testTransactionAllStatuses(): void
    {
        $doc = new Document(['$id' => 'tx-test', 'metadata' => []]);
        $tx = new Transaction($doc);

        $tx->setStatus(TransactionStatus::Pending);
        $this->assertTrue($tx->isPending());
        $this->assertFalse($tx->isSucceeded());
        $this->assertFalse($tx->isFailed());

        $tx->setStatus(TransactionStatus::Succeeded);
        $this->assertTrue($tx->isSucceeded());

        $tx->setStatus(TransactionStatus::Failed);
        $this->assertTrue($tx->isFailed());
    }

    public function testTransactionAllSettersGetters(): void
    {
        $doc = new Document(['$id' => 'tx-cov', 'metadata' => '{}']);
        $tx = new Transaction($doc);

        $tx->setEntityId('ent-1');
        $this->assertEquals('ent-1', $tx->getEntityId());

        $tx->setInvoiceId('inv-1');
        $this->assertEquals('inv-1', $tx->getInvoiceId());

        $tx->setType(TransactionType::GatewayCharge);
        $this->assertEquals(TransactionType::GatewayCharge, $tx->getType());

        $tx->setAmount(99.99);
        $this->assertEquals(99.99, $tx->getAmount());

        $tx->setStatus(TransactionStatus::Succeeded);
        $this->assertEquals(TransactionStatus::Succeeded, $tx->getStatus());

        $tx->setWalletId('wal-1');
        $this->assertEquals('wal-1', $tx->getWalletId());

        $tx->setProviderPaymentId('pi_stripe_123');
        $this->assertEquals('pi_stripe_123', $tx->getProviderPaymentId());

        $tx->setDescription('Test charge');
        $this->assertEquals('Test charge', $tx->getDescription());

        $tx->setMetadata(['ref' => 'order-123']);
        $this->assertEquals(['ref' => 'order-123'], $tx->getMetadata());

        $this->assertInstanceOf(Document::class, $tx->getDocument());
    }
}
