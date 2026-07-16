<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Estimate;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stamps the creator on create, then recomputes every monetary total from
 * the line items server-side (#652) — the quote-side twin of
 * InvoiceProcessor: line amount = round(quantity × unitAmount); subtotal =
 * Σ amounts; taxAmount = round(subtotal × taxRate / 10000); total =
 * subtotal + taxAmount.
 *
 * @implements ProcessorInterface<Estimate, Estimate>
 */
final class EstimateProcessor implements ProcessorInterface
{
    private const BASIS_POINTS = 10000;

    /**
     * @param ProcessorInterface<Estimate, Estimate> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
    ) {
    }

    /**
     * @param Estimate $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Estimate
    {
        $user = $this->auth->requireUser('manage estimates');

        if (null === $data->getCreatedBy()) {
            $data->setCreatedBy($user);
        }

        $subtotal = 0;
        foreach ($data->getLineItems() as $line) {
            $amount = (int) round($line->getQuantity() * $line->getUnitAmount());
            $line->setAmount($amount);
            $subtotal += $amount;
        }
        $taxAmount = (int) round($subtotal * $data->getTaxRate() / self::BASIS_POINTS);
        $data->setSubtotal($subtotal);
        $data->setTaxAmount($taxAmount);
        $data->setTotal($subtotal + $taxAmount);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
