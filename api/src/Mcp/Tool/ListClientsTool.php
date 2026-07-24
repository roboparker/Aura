<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Client;
use App\Entity\User;
use App\Mcp\McpBillingAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Security\Permission\SpacePermission;
use Doctrine\ORM\EntityManagerInterface;

final class ListClientsTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpBillingAuthorization $authz,
        private McpEntitySerializer $serializer,
    ) {
    }

    public function getName(): string
    {
        return 'list_clients';
    }

    public function getDescription(): string
    {
        return 'List the billing clients the user can see, alphabetically. Use to resolve a client name to an id before filtering invoices.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'spaceId' => ['type' => 'string', 'description' => 'Only return clients in this space.'],
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

        $qb = $this->em->getRepository(Client::class)
            ->createQueryBuilder('c')
            ->where('c.space IN (:spaces)')
            ->setParameter('spaces', $spaces)
            ->orderBy('c.name', 'ASC');

        $spaceId = $arguments['spaceId'] ?? null;
        if (is_string($spaceId) && '' !== $spaceId) {
            $qb->andWhere('c.space = :spaceId')->setParameter('spaceId', $spaceId);
        }

        return [
            'items' => array_map(
                fn (Client $client) => $this->serializer->client($client),
                $qb->getQuery()->getResult(),
            ),
        ];
    }
}
