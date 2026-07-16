<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Invoice;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stamps the creator on create, then recomputes every monetary total from the
 * line items server-side (never trusting client-sent amounts): each line's
 * amount = round(quantity × unitAmount); the invoice's subtotal = Σ amounts;
 * taxAmount = round(subtotal × taxRate / 10000); total = subtotal + taxAmount.
 *
 * Runs on both create and update so edited line items always re-total.
 *
 * @implements ProcessorInterface<Invoice, Invoice>
 */
final class InvoiceProcessor implements ProcessorInterface
{
    private const BASIS_POINTS = 10000;

    /**
     * @param ProcessorInterface<Invoice, Invoice> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
    ) {
    }

    /**
     * @param Invoice $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Invoice
    {
        $user = $this->auth->requireUser('manage invoices');

        if (null === $data->getCreatedBy()) {
            $data->setCreatedBy($user);
        }

        $this->recompute($data);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    private function recompute(Invoice $invoice): void
    {
        $subtotal = 0;
        foreach ($invoice->getLineItems() as $line) {
            $amount = (int) round($line->getQuantity() * $line->getUnitAmount());
            $line->setAmount($amount);
            $subtotal += $amount;
        }

        // Discount (#648) applies between subtotal and tax: percent of the
        // subtotal or a fixed amount, never more than the subtotal itself.
        $discountValue = $invoice->getDiscountValue();
        $discountAmount = 0;
        if (null !== $invoice->getDiscountType() && null !== $discountValue) {
            $discountAmount = Invoice::DISCOUNT_PERCENT === $invoice->getDiscountType()
                ? (int) round($subtotal * $discountValue / self::BASIS_POINTS)
                : $discountValue;
            $discountAmount = max(0, min($discountAmount, $subtotal));
        }

        $taxable = $subtotal - $discountAmount;
        $taxAmount = (int) round($taxable * $invoice->getTaxRate() / self::BASIS_POINTS);
        $invoice->setSubtotal($subtotal);
        $invoice->setDiscountAmount($discountAmount);
        $invoice->setTaxAmount($taxAmount);
        $invoice->setTotal($taxable + $taxAmount);
    }
}
