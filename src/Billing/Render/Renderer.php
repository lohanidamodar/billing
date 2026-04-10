<?php

declare(strict_types=1);

namespace Utopia\Billing\Render;

use Utopia\Billing\Invoice;

/**
 * Renderer
 *
 * Abstract base class for invoice rendering. Concrete implementations produce
 * output in specific formats (HTML, PDF, etc.) from an Invoice object.
 *
 * Options may include:
 * - 'template' — path to a custom template file
 * - 'entity'   — array with customer/entity info (name, address, email, etc.)
 * - 'issuer'   — array with issuer/company info (name, address, tax ID, etc.)
 */
abstract class Renderer
{
    /**
     * Render an invoice to a string in the target format.
     *
     * @param Invoice              $invoice The invoice to render
     * @param array<string, mixed> $options Rendering options (template, entity, issuer, etc.)
     *
     * @return string The rendered output
     */
    abstract public function render(Invoice $invoice, array $options = []): string;
}
