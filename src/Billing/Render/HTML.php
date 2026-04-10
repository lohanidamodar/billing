<?php

declare(strict_types=1);

namespace Utopia\Billing\Render;

use Utopia\Billing\Invoice;

/**
 * HTML Renderer
 *
 * Renders an invoice as self-contained HTML using `.phtml` template files.
 * The default template is shipped at `templates/invoice.phtml` and produces
 * a clean, print-friendly invoice with inline CSS (zero external dependencies).
 *
 * Variables available inside the template:
 * - $invoice — Invoice object
 * - $items   — array of line items from the invoice
 * - $entity  — array with customer/entity info (name, address, email, etc.)
 * - $issuer  — array with issuer/company info (name, address, tax ID, etc.)
 */
class HTML extends Renderer
{
    /**
     * Path to the default invoice template.
     */
    protected string $defaultTemplate;

    /**
     * HTML renderer constructor.
     *
     * @param  string|null  $defaultTemplate  Path to the default .phtml template.
     *                                        Falls back to the bundled template if null.
     */
    public function __construct(?string $defaultTemplate = null)
    {
        $this->defaultTemplate = $defaultTemplate
            ?? realpath(__DIR__.'/../../../templates/invoice.phtml')
            ?: __DIR__.'/../../../templates/invoice.phtml';
    }

    /**
     * Render an invoice as HTML.
     *
     * Supported options:
     * - 'template' (string) — path to a custom .phtml template
     * - 'entity'   (array)  — customer info (name, address, email, etc.)
     * - 'issuer'   (array)  — company/issuer info (name, address, taxId, etc.)
     *
     * @param  Invoice  $invoice  The invoice to render
     * @param  array<string, mixed>  $options  Rendering options
     * @return string The rendered HTML
     *
     * @throws \RuntimeException If the template file does not exist
     */
    public function render(Invoice $invoice, array $options = []): string
    {
        /** @var string $template */
        $template = $options['template'] ?? $this->defaultTemplate;
        /** @var array<string, mixed> $entity */
        $entity = $options['entity'] ?? [];
        /** @var array<string, mixed> $issuer */
        $issuer = $options['issuer'] ?? [];
        $items = $invoice->getItems();

        if (! \file_exists($template)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        \ob_start();

        try {
            require $template;
        } catch (\Throwable $e) {
            \ob_end_clean();

            throw $e;
        }

        return (string) \ob_get_clean();
    }
}
