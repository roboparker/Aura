<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Expense;
use App\Entity\User;
use App\Mcp\McpBillingAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpInputHelper;
use App\Security\Permission\SpacePermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Expenses ride the `time_entries` category, not `invoices` — mirroring the
 * REST `security:` expressions. They're a cost-tracking concern like time
 * (everyone records their own), distinct from seeing what the business bills.
 */
final class ListExpensesTool implements McpToolInterface
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
        return 'list_expenses';
    }

    public function getDescription(): string
    {
        return 'List recorded expenses, newest first. Defaults to the user\'s own; filter by date range or project. Use for "what have I spent" and reimbursement questions.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Only expenses spent on or after this Y-m-d date.'],
                'to' => ['type' => 'string', 'description' => 'Only expenses spent on or before this Y-m-d date.'],
                'projectId' => ['type' => 'string', 'description' => 'Only expenses on this client project.'],
                'mine' => ['type' => 'boolean', 'description' => 'Restrict to the user\'s own expenses. Defaults to true.'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum to return (1-100, default 50).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $spaces = $this->authz->readableSpaces($user, SpacePermission::TIME_ENTRIES);
        if ([] === $spaces) {
            return ['items' => []];
        }

        $qb = $this->em->getRepository(Expense::class)
            ->createQueryBuilder('e')
            ->where('e.space IN (:spaces)')
            ->setParameter('spaces', $spaces)
            ->orderBy('e.spentOn', 'DESC');

        if (false !== ($arguments['mine'] ?? true)) {
            $qb->andWhere('e.user = :user')->setParameter('user', $user);
        }

        $from = $this->input->optionalDateTime($arguments, 'from');
        if (null !== $from) {
            $qb->andWhere('e.spentOn >= :from')->setParameter('from', $from);
        }

        $to = $this->input->optionalDateTime($arguments, 'to');
        if (null !== $to) {
            $qb->andWhere('e.spentOn <= :to')->setParameter('to', $to);
        }

        $projectId = $this->input->optionalUuid('projectId', $arguments['projectId'] ?? null);
        if (null !== $projectId) {
            $qb->andWhere('e.project = :project')->setParameter('project', $projectId);
        }

        $limit = $this->input->optionalInt($arguments, 'limit') ?? 50;
        $qb->setMaxResults(max(1, min(100, $limit)));

        return [
            'items' => array_map(
                fn (Expense $expense) => $this->serializer->expense($expense),
                $qb->getQuery()->getResult(),
            ),
        ];
    }
}
