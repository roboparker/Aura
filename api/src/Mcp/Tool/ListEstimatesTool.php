<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Estimate;
use App\Entity\User;
use App\Mcp\McpBillingAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Security\Permission\SpacePermission;
use Doctrine\ORM\EntityManagerInterface;

final class ListEstimatesTool implements McpToolInterface
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
        return 'list_estimates';
    }

    public function getDescription(): string
    {
        return 'List client estimates (quotes) with their totals and status, newest first. Use for "what have we quoted / what is still pending acceptance" questions.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => Estimate::STATUSES,
                    'description' => 'Only estimates in this status.',
                ],
                'clientId' => ['type' => 'string', 'description' => 'Only estimates for this client.'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum to return (1-100, default 50).'],
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

        $qb = $this->em->getRepository(Estimate::class)
            ->createQueryBuilder('e')
            ->where('e.space IN (:spaces)')
            ->setParameter('spaces', $spaces)
            ->orderBy('e.createdAt', 'DESC');

        $status = $this->input->optionalString($arguments, 'status');
        if (null !== $status) {
            if (!in_array($status, Estimate::STATUSES, true)) {
                throw McpException::invalidInput(sprintf(
                    'Unknown status "%s". Expected one of: %s.',
                    $status,
                    implode(', ', Estimate::STATUSES),
                ));
            }
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }

        $clientId = $this->input->optionalUuid('clientId', $arguments['clientId'] ?? null);
        if (null !== $clientId) {
            $qb->andWhere('e.client = :client')->setParameter('client', $clientId);
        }

        $limit = $this->input->optionalInt($arguments, 'limit') ?? 50;
        $qb->setMaxResults(max(1, min(100, $limit)));

        return [
            'items' => array_map(
                fn (Estimate $estimate) => $this->serializer->estimate($estimate),
                $qb->getQuery()->getResult(),
            ),
        ];
    }
}
