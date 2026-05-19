<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;
use Utopia\Billing\Invoice;
use Utopia\Billing\InvoiceStatus;
use Utopia\Database\Document;

class InvoiceTest extends TestCase
{
    public function testInvoiceModel(): void
    {
        $doc = new Document([
            '$id' => 'inv-1',
            'entityId' => 'entity-1',
            'type' => 'subscription',
            'status' => 'draft',
            'items' => [
                ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.0],
                ['type' => 'usage', 'description' => 'BW', 'amount' => 3.0],
                ['type' => 'discount', 'description' => 'Disc', 'amount' => -1.5],
            ],
            'subtotal' => 18.0,
            'total' => 16.5,
            'currency' => 'USD',
            'metadata' => [],
        ]);

        $invoice = new Invoice($doc);

        $this->assertEquals('inv-1', $invoice->getId());
        $this->assertFalse($invoice->isFinalized());
        $this->assertFalse($invoice->isCreditNote());
        $this->assertCount(3, $invoice->getItems());
        $this->assertCount(1, $invoice->getItemsByType('plan'));
        $this->assertCount(1, $invoice->getItemsByType('discount'));
        $this->assertEquals('invoices', Invoice::getName());
    }

    public function testInvoiceAllStatusesFinalized(): void
    {
        $doc = new Document(['$id' => 'inv-test', 'items' => [], 'metadata' => []]);
        $inv = new Invoice($doc);

        foreach (InvoiceStatus::cases() as $status) {
            $inv->setStatus($status);
            $this->assertEquals($status, $inv->getStatus());
            $this->assertEquals($status->isFinalized(), $inv->isFinalized());
        }
    }

    public function testInvoiceAllSettersGetters(): void
    {
        $doc = new Document(['$id' => 'inv-cov', 'items' => '[]', 'metadata' => '{}']);
        $inv = new Invoice($doc);

        $inv->setSubscriptionId('sub-1');
        $this->assertEquals('sub-1', $inv->getSubscriptionId());

        $inv->setReferenceInvoiceId('inv-ref');
        $this->assertEquals('inv-ref', $inv->getReferenceInvoiceId());

        $inv->setEntityId('ent-1');
        $this->assertEquals('ent-1', $inv->getEntityId());

        $inv->setType('custom_type');
        $this->assertEquals('custom_type', $inv->getType());

        $inv->setNumber('INV-001');
        $this->assertEquals('INV-001', $inv->getNumber());

        $inv->setStatus(InvoiceStatus::Finalized);
        $this->assertEquals(InvoiceStatus::Finalized, $inv->getStatus());

        $inv->setItems([['type' => 'plan', 'amount' => 10.0]]);
        $this->assertCount(1, $inv->getItems());

        $inv->addItem(['type' => 'addon', 'amount' => 5.0]);
        $this->assertCount(2, $inv->getItems());

        $inv->setSubtotal(15.0);
        $this->assertEquals(15.0, $inv->getSubtotal());

        $inv->setDiscountTotal(2.0);
        $this->assertEquals(2.0, $inv->getDiscountTotal());

        $inv->setTaxTotal(1.5);
        $this->assertEquals(1.5, $inv->getTaxTotal());

        $inv->setTotal(14.5);
        $this->assertEquals(14.5, $inv->getTotal());

        $inv->setWalletDeducted(5.0);
        $this->assertEquals(5.0, $inv->getWalletDeducted());

        $inv->setGatewayCharged(9.5);
        $this->assertEquals(9.5, $inv->getGatewayCharged());

        $inv->setCurrency('EUR');
        $this->assertEquals('EUR', $inv->getCurrency());

        $inv->setDueDate('2026-05-01T00:00:00.000+00:00');
        $this->assertEquals('2026-05-01T00:00:00.000+00:00', $inv->getDueDate());

        $inv->setPaidAt('2026-04-15T00:00:00.000+00:00');
        $this->assertEquals('2026-04-15T00:00:00.000+00:00', $inv->getPaidAt());

        $inv->setMetadata(['ref' => '123']);
        $this->assertEquals(['ref' => '123'], $inv->getMetadata());

        $this->assertInstanceOf(Document::class, $inv->getDocument());
    }
}
