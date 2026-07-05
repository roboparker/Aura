<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CustomFieldDefinition;
use App\Entity\Board;
use App\Entity\User;
use App\Service\ActivityFeedQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Board-level change log for the custom-fields schema:
 * `GET /boards/{id}/custom_field_definitions/activity`. Merges the
 * Gedmo audit history of every custom field definition in the board,
 * newest-first, reusing {@see ActivityFeedQuery} so the shape matches the
 * task/board feeds (rows + actor map).
 *
 * Fields that have since been deleted stay in the log: their rows outlive
 * the entity (the audit table has no FK to it), and we recover their object
 * ids from the versioned `board` stamped on each create/update entry — so a
 * deleted field's history, ending in its `remove` event, remains visible.
 * Access mirrors the board surface: any space member; 404 otherwise.
 */
class CustomFieldDefinitionActivityController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActivityFeedQuery $activityFeed,
    ) {
    }

    #[Route(
        '/boards/{id}/custom_field_definitions/activity',
        name: 'board_custom_field_definitions_activity',
        methods: ['GET'],
    )]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $board = $this->em->getRepository(Board::class)->find($id);
        if (null === $board || !$this->canRead($board, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        // Fields are space-owned now; the change log covers the space's fields.
        $definitions = $this->em->getRepository(CustomFieldDefinition::class)
            ->findBy(['space' => $board->getSpace()]);
        $currentIds = array_map(
            static fn (CustomFieldDefinition $d): string => (string) $d->getId(),
            $definitions,
        );

        // Union current ids with the ids of fields that were deleted from this
        // space (recovered from the audit log via the versioned `space`).
        $objectIds = array_values(array_unique(
            [...$currentIds, ...$this->deletedDefinitionIds($board)],
        ));

        return new JsonResponse(
            $this->activityFeed->forClass(CustomFieldDefinition::class, $objectIds, $request),
        );
    }

    /**
     * Object ids of custom field definitions that once belonged to this
     * board's space but no longer exist — read from the audit log's
     * versioned `space`.
     *
     * @return list<string>
     */
    private function deletedDefinitionIds(Board $board): array
    {
        $space = $board->getSpace();
        if (null === $space) {
            return [];
        }
        // Gedmo stores the versioned association nested as {"id": "<uuid>"}.
        $rows = $this->em->getConnection()->executeQuery(
            "SELECT DISTINCT object_id FROM ext_log_entries
             WHERE object_class = :class AND data->'space'->>'id' = :space",
            [
                'class' => CustomFieldDefinition::class,
                'space' => (string) $space->getId(),
            ],
        )->fetchFirstColumn();

        // object_id is a VARCHAR column, so values are strings; filter defensively.
        return array_values(array_filter(
            $rows,
            static fn (mixed $id): bool => is_string($id),
        ));
    }

    private function canRead(Board $board, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        return $board->isAccessibleBy($user);
    }
}
