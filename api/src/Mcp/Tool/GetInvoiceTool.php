<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Invoice;
use App\Entity\User;
use App\Mcp\McpBillingAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class GetInvoiceTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpBillingAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'get_invoice';
    }

    public function getDescription(): string
    {
        return 'Fetch one invoice in full, including its line items, tax and payments. Use after list_invoices when the detail of a specific invoice matters.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoiceId' => ['type' => 'string', 'description' => 'UUID of the invoice.'],
            ],
            'required' => ['invoiceId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $invoiceId = $this->input->requireUuid('invoiceId', $this->input->requireString($arguments, 'invoiceId'));

        $invoice = $this->em->getRepository(Invoice::class)->find($invoiceId);
        // Same 404-for-forbidden shape the access extensions use: an outsider
        // learns nothing about whether the invoice exists.
        if (null === $invoice || !$this->authz->canReadInvoice($invoice, $user)) {
            throw McpException::notFound(sprintf('Invoice %s', $invoiceId));
        }

        return $this->serializer->invoice($invoice, withLineItems: true);
    }
}
