<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Repository\InvoiceRepository;

/**
 * Assigns the next sequential invoice number (#669) — one code path for the
 * issue and send transitions so branding (custom prefix + explicit next
 * counter) can't drift between them.
 *
 * Prefix defaults to "INV-"; the counter starts from the space's
 * `invoiceNumberNext` when set (admin-configurable), else falls back to
 * count-of-numbered + 1 (the pre-branding behaviour). Every assignment
 * advances the counter, so once a space issues one branded invoice the
 * explicit counter is authoritative and renumbering/prefix changes never
 * reuse a value.
 */
final class InvoiceNumberer
{
    public function __construct(private readonly InvoiceRepository $invoices)
    {
    }

    /** No-op when the invoice is already numbered or has no space. */
    public function assign(Invoice $invoice): void
    {
        $space = $invoice->getSpace();
        if (null === $space || null !== $invoice->getNumber()) {
            return;
        }

        $prefix = $space->getInvoiceNumberPrefix();
        if (null === $prefix || '' === trim($prefix)) {
            $prefix = 'INV-';
        }
        $next = $space->getInvoiceNumberNext() ?? ($this->invoices->countNumbered($space) + 1);

        $invoice->setNumber($prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT));
        $space->setInvoiceNumberNext($next + 1);
    }
}
