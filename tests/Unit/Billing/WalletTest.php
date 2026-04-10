<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;
use Utopia\Billing\Wallet;
use Utopia\Database\Document;

class WalletTest extends TestCase
{
    public function test_wallet_model(): void
    {
        $doc = new Document([
            '$id' => 'wal-1',
            'entityId' => 'entity-1',
            'balance' => 50.0,
            'currency' => 'USD',
            'metadata' => [],
        ]);

        $wallet = new Wallet($doc);

        $this->assertTrue($wallet->hasSufficientBalance(30.0));
        $this->assertFalse($wallet->hasSufficientBalance(100.0));
        $this->assertFalse($wallet->isEmpty());
        $this->assertEquals('wallets', Wallet::getName());
    }

    public function test_wallet_boundary_balance(): void
    {
        $doc = new Document(['$id' => 'wal-test', 'balance' => 50.0, 'metadata' => []]);
        $wallet = new Wallet($doc);

        $this->assertTrue($wallet->hasSufficientBalance(50.0)); // exact match
        $this->assertFalse($wallet->hasSufficientBalance(50.01));
        $this->assertTrue($wallet->hasSufficientBalance(0.0));

        $wallet->setBalance(0.0);
        $this->assertTrue($wallet->isEmpty());

        $wallet->setBalance(-1.0);
        $this->assertTrue($wallet->isEmpty());
    }

    public function test_wallet_all_setters_getters(): void
    {
        $doc = new Document(['$id' => 'wal-cov', 'metadata' => '{}']);
        $wal = new Wallet($doc);

        $wal->setEntityId('ent-1');
        $this->assertEquals('ent-1', $wal->getEntityId());

        $wal->setBalance(150.0);
        $this->assertEquals(150.0, $wal->getBalance());

        $wal->setCurrency('GBP');
        $this->assertEquals('GBP', $wal->getCurrency());

        $wal->setMetadata(['tier' => 'gold']);
        $this->assertEquals(['tier' => 'gold'], $wal->getMetadata());

        $this->assertInstanceOf(Document::class, $wal->getDocument());
    }
}
