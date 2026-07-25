<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Invoice;
use App\Entity\User;
use App\Mcp\McpBillingAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Security\Permission\SpacePermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lists invoices without their line items — a page of fully-expanded invoices
 * would burn context for questions ("who owes me money?") that only need the
 * totals. {@see GetInvoiceTool} expands one.
 */
final class ListInvoicesTool implements McpToolInterface
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
        return 'list_invoices';
    }

    public function getDescription(): string
    {
        return 'List invoices with their totals and balance due, newest first. Filter by status or client. Use for "what is unpaid / overdue / outstanding" questions; call get_invoice for line-item detail.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => Invoice::STATUSES,
                    'description' => 'Only invoices in this status.',
                ],
                'clientId' => ['type' => 'string', 'description' => 'Only invoices for this client.'],
                'unpaidOnly' => ['type' => 'boolean', 'description' => 'Only invoices with a balance still outstanding.'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum invoices to return (1-100, default 50).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $spaces = $this->authz->readableSpaces($user, SpacePermission::INVOICES);
        if ([] === $spaces) {
            return ['items' => []];
        }

        $qb = $this->em->getRepository(Invoice::class)
            ->createQueryBuilder('i')
            ->where('i.space IN (:spaces)')
            ->setParameter('spaces', $spaces)
            ->orderBy('i.issueDate', 'DESC')
            ->addOrderBy('i.createdAt', 'DESC');

        $status = $this->input->optionalString($arguments, 'status');
        if (null !== $status) {
            if (!in_array($status, Invoice::STATUSES, true)) {
                throw McpException::invalidInput(
                    sprintf('Unknown status "%s". Expected one of: %s.', $status, implode(', ', Invoice::STATUSES)),
                );
            }
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }

        $clientId = $this->input->optionalUuid('clientId', $arguments['clientId'] ?? null);
        if (null !== $clientId) {
            $qb->andWhere('i.client = :client')->setParameter('client', $clientId);
        }

        if (true === ($arguments['unpaidOnly'] ?? false)) {
            // Void invoices carry a balance on paper but are cancelled — a
            // model asking "what's outstanding" must not be told to chase them.
            $qb->andWhere('i.status NOT IN (:settled)')
                ->setParameter('settled', [Invoice::STATUS_PAID, Invoice::STATUS_VOID]);
        }

        $limit = $this->input->optionalInt($arguments, 'limit') ?? 50;
        $qb->setMaxResults(max(1, min(100, $limit)));

        return [
            'items' => array_map(
                fn (Invoice $invoice) => $this->serializer->invoice($invoice),
                $qb->getQuery()->getResult(),
            ),
        ];
    }
}
