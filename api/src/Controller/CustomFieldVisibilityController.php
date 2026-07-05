<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CustomFieldDefinition;
use App\Entity\GlobalCustomFieldDefinition;
use App\Entity\Board;
use App\Entity\BoardFieldVisibility;
use App\Entity\User;
use App\Repository\BoardFieldVisibilityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * `PUT /boards/{id}/custom_field_definitions/{defId}/visibility` — set where a
 * field is shown WITHIN this board (#custom-fields-board): the task list,
 * the Kanban board, or both. Body: `{"visibility": "list"|"board"|"both"}`.
 *
 * Upserts a {@see BoardFieldVisibility} row for the (board, definition)
 * pair; the definition's own column stays the cross-board default. The field
 * must already be attached to the board.
 *
 * Access mirrors the reorder/define endpoints: any space member of the board
 * (admins always). Non-members — and unknown ids — get 404 to match the
 * existence-hiding shape of the rest of the board surface.
 */
final class CustomFieldVisibilityController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BoardFieldVisibilityRepository $overrides,
    ) {
    }

    #[Route(
        '/boards/{id}/custom_field_definitions/{defId}/visibility',
        name: 'board_custom_field_definition_visibility',
        methods: ['PUT'],
    )]
    public function __invoke(
        string $id,
        string $defId,
        Request $request,
        #[CurrentUser] ?User $user,
    ): Response {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id) || !Uuid::isValid($defId)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $board = $this->em->getRepository(Board::class)->find($id);
        if (null === $board || !$this->canManage($board, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $definition = $this->em->getRepository(CustomFieldDefinition::class)->find($defId);
        if (null === $definition || !$board->getCustomFieldDefinitions()->contains($definition)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        // Accept a comma-joined SET of surfaces (list/board/calendar) or the
        // legacy single value; normalise to a deduped, ordered surface string.
        $visibility = $this->parseVisibility($request);
        if (null === $visibility) {
            return new JsonResponse(
                ['error' => 'visibility must list one or more of: ' . implode(', ', CustomFieldDefinition::SURFACES) . '.'],
                400,
            );
        }

        $row = $this->overrides->findOneFor($board, $definition);
        if (null === $row) {
            $row = (new BoardFieldVisibility())
                ->setBoard($board)
                ->setDefinition($definition);
            $this->em->persist($row);
        }
        $row->setVisibility($visibility);
        $this->em->flush();

        return new JsonResponse(['visibility' => $visibility], 200);
    }

    /**
     * The global-field twin of {@see __invoke()}: sets where an instance-wide
     * global field shows WITHIN this board. Same access + body contract; the
     * override targets the polymorphic global arm of {@see BoardFieldVisibility}.
     */
    #[Route(
        '/boards/{id}/global_custom_field_definitions/{defId}/visibility',
        name: 'board_global_custom_field_definition_visibility',
        methods: ['PUT'],
    )]
    public function globalVisibility(
        string $id,
        string $defId,
        Request $request,
        #[CurrentUser] ?User $user,
    ): Response {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id) || !Uuid::isValid($defId)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $board = $this->em->getRepository(Board::class)->find($id);
        if (null === $board || !$this->canManage($board, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $definition = $this->em->getRepository(GlobalCustomFieldDefinition::class)->find($defId);
        if (null === $definition || !$board->getGlobalCustomFieldDefinitions()->contains($definition)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $visibility = $this->parseVisibility($request);
        if (null === $visibility) {
            return new JsonResponse(
                ['error' => 'visibility must list one or more of: ' . implode(', ', CustomFieldDefinition::SURFACES) . '.'],
                400,
            );
        }

        $row = $this->overrides->findOneForGlobal($board, $definition);
        if (null === $row) {
            $row = (new BoardFieldVisibility())
                ->setBoard($board)
                ->setGlobalDefinition($definition);
            $this->em->persist($row);
        }
        $row->setVisibility($visibility);
        $this->em->flush();

        return new JsonResponse(['visibility' => $visibility], 200);
    }

    /**
     * Parse + normalise the `{visibility}` body into a deduped, ordered
     * comma-joined surface string, or null when it names no valid surface.
     */
    private function parseVisibility(Request $request): ?string
    {
        $payload = $request->toArray();
        $raw = $payload['visibility'] ?? null;
        $surfaces = is_string($raw) ? CustomFieldDefinition::visibilitySurfaces($raw) : [];
        if ([] === $surfaces) {
            return null;
        }

        return implode(',', array_values(array_unique($surfaces)));
    }

    private function canManage(Board $board, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // Fields are managed by any space member (#custom-fields-space).
        return $board->isAccessibleBy($user);
    }
}
