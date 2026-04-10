<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Billing\Render;

use PHPUnit\Framework\TestCase;
use Utopia\Billing\Invoice;
use Utopia\Billing\Render\HTML;
use Utopia\Database\Document;

class HTMLTest extends TestCase
{
    public function test_html_renderer_basic(): void
    {
        $renderer = new HTML;

        $doc = new Document([
            '$id' => 'inv-render-1',
            'entityId' => 'entity-1',
            'type' => 'subscription',
            'number' => 'INV-2026-00001',
            'status' => 'paid',
            'items' => [
                ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.00],
                ['type' => 'tax', 'description' => 'VAT (17%)', 'amount' => 2.55, 'rate' => 17.0],
            ],
            'subtotal' => 15.00,
            'discountTotal' => 0.0,
            'taxTotal' => 2.55,
            'total' => 17.55,
            'walletDeducted' => 0.0,
            'gatewayCharged' => 17.55,
            'currency' => 'USD',
            'dueDate' => null,
            'paidAt' => '2026-04-10T12:00:00.000+00:00',
            'metadata' => [],
        ]);

        $invoice = new Invoice($doc);
        $html = $renderer->render($invoice);

        $this->assertStringContainsString('INV-2026-00001', $html);
        $this->assertStringContainsString('Pro Plan', $html);
        $this->assertStringContainsString('PAID', $html);
        $this->assertStringContainsString('$15.00', $html);
        $this->assertStringContainsString('$17.55', $html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
    }

    public function test_html_renderer_with_entity_and_issuer(): void
    {
        $renderer = new HTML;

        $doc = new Document([
            '$id' => 'inv-render-2',
            'entityId' => 'entity-1',
            'type' => 'subscription',
            'number' => 'INV-2026-00002',
            'status' => 'finalized',
            'items' => [],
            'subtotal' => 0.0,
            'discountTotal' => 0.0,
            'taxTotal' => 0.0,
            'total' => 0.0,
            'walletDeducted' => 0.0,
            'gatewayCharged' => 0.0,
            'currency' => 'USD',
            'dueDate' => '2026-05-01T00:00:00.000+00:00',
            'paidAt' => null,
            'metadata' => [],
        ]);

        $invoice = new Invoice($doc);
        $html = $renderer->render($invoice, [
            'entity' => ['name' => 'Acme Corp', 'email' => 'billing@acme.com'],
            'issuer' => ['name' => 'Utopia Inc', 'taxId' => 'US-12345'],
        ]);

        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('billing@acme.com', $html);
        $this->assertStringContainsString('Utopia Inc', $html);
        $this->assertStringContainsString('US-12345', $html);
    }

    public function test_html_renderer_credit_note(): void
    {
        $renderer = new HTML;

        $doc = new Document([
            '$id' => 'inv-cn-1',
            'entityId' => 'entity-1',
            'type' => 'credit_note',
            'referenceInvoiceId' => 'inv-original',
            'number' => 'CN-2026-00001',
            'status' => 'finalized',
            'items' => [
                ['type' => 'refund', 'description' => 'Refund Plan', 'amount' => -15.00],
            ],
            'subtotal' => -15.00,
            'discountTotal' => 0.0,
            'taxTotal' => 0.0,
            'total' => -15.00,
            'walletDeducted' => 0.0,
            'gatewayCharged' => 0.0,
            'currency' => 'EUR',
            'dueDate' => null,
            'paidAt' => null,
            'metadata' => [],
        ]);

        $invoice = new Invoice($doc);
        $html = $renderer->render($invoice);

        $this->assertStringContainsString('Credit Note', $html);
        $this->assertStringContainsString('inv-original', $html);
    }

    public function test_html_renderer_template_not_found(): void
    {
        $renderer = new HTML;
        $doc = new Document([
            '$id' => 'inv-1',
            'type' => 'subscription',
            'status' => 'draft',
            'items' => [],
            'currency' => 'USD',
            'metadata' => [],
        ]);
        $invoice = new Invoice($doc);

        $this->expectException(\RuntimeException::class);
        $renderer->render($invoice, ['template' => '/nonexistent/template.phtml']);
    }

    public function test_html_renderer_all_item_types(): void
    {
        $renderer = new HTML;

        $doc = new Document([
            '$id' => 'inv-all',
            'entityId' => 'entity-1',
            'type' => 'subscription',
            'number' => 'INV-ALL',
            'status' => 'finalized',
            'items' => [
                ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.00],
                ['type' => 'usage', 'description' => 'Bandwidth 50GB', 'amount' => 4.00, 'resource' => 'bandwidth', 'quantity' => 50.0, 'unit' => 'GB', 'unitPrice' => 0.08],
                ['type' => 'addon', 'description' => 'HIPAA', 'amount' => 350.00],
                ['type' => 'proration', 'description' => 'Proration credit', 'amount' => -5.00],
                ['type' => 'discount', 'description' => '10% off', 'amount' => -1.50],
                ['type' => 'tax', 'description' => 'VAT 17%', 'amount' => 61.63, 'rate' => 17.0],
                ['type' => 'refund', 'description' => 'Partial refund', 'amount' => -2.00],
            ],
            'subtotal' => 364.00,
            'discountTotal' => 1.50,
            'taxTotal' => 61.63,
            'total' => 422.13,
            'walletDeducted' => 22.13,
            'gatewayCharged' => 400.00,
            'currency' => 'USD',
            'dueDate' => null,
            'paidAt' => null,
            'metadata' => [],
        ]);

        $invoice = new Invoice($doc);
        $html = $renderer->render($invoice);

        // Verify all item types rendered
        $this->assertStringContainsString('Plan', $html);
        $this->assertStringContainsString('Bandwidth', $html);
        $this->assertStringContainsString('HIPAA', $html);
        $this->assertStringContainsString('Proration', $html);
        $this->assertStringContainsString('10% off', $html);
        $this->assertStringContainsString('17.0%', $html);
        $this->assertStringContainsString('Partial refund', $html);
        // Verify payment breakdown
        $this->assertStringContainsString('Wallet Deducted', $html);
        $this->assertStringContainsString('Gateway Charged', $html);
    }

    public function test_html_renderer_constructor(): void
    {
        // Default template
        $renderer = new HTML;
        $this->assertInstanceOf(HTML::class, $renderer);

        // Custom default template
        $template = __DIR__.'/../../../../templates/invoice.phtml';
        $renderer2 = new HTML($template);
        $this->assertInstanceOf(HTML::class, $renderer2);
    }
}
