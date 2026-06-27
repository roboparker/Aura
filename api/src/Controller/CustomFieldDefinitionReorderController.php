<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /projects/{id}/custom_field_definitions/reorder` — bulk renumber
 * the `position` of a project's custom field definitions. Accepts an
 * ordered list of CFD IRIs and renumbers `position` (0, 1, 2, …) on each
 * in input order. The order on this page drives the column order on the
 * task list.
 *
 * Access: caller must be a space admin of the project (mirrors the
 * entity-level `Patch`/`Delete` security expression). Non-admins — and
 * non-members — get 404 so the endpoint matches the existence-hiding
 * shape of the rest of the project surface.
 *
 * Rejects the whole payload when:
 *   - any IRI is malformed,
 *   - the input contains a duplicate, or
 *   - the input doesn't list every one of the project's definitions
 *     exactly once (keeps `position` contiguous).
 */
final class CustomFieldDefinitionReorderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route(
        '/projects/{id}/custom_field_definitions/reorder',
        name: 'project_custom_field_definitions_reorder',
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $project = $this->em->getRepository(Project::class)->find($id);
        if (null === $project || !$this->canAdmin($project, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !isset($payload['order']) || !is_array($payload['order'])) {
            return new JsonResponse(
                ['error' => 'Expected body: {"order": ["/custom_field_definitions/{uuid}", ...]}.'],
                400,
            );
        }

        $ids = [];
        foreach ($payload['order'] as $iri) {
            if (
                !is_string($iri)
                || 1 !== preg_match('#^/custom_field_definitions/([0-9a-fA-F-]{36})$#', $iri, $match)
                || !Uuid::isValid($match[1])
            ) {
                return new JsonResponse(
                    ['error' => sprintf('Invalid CustomFieldDefinition IRI: %s', is_string($iri) ? $iri : gettype($iri))],
                    400,
                );
            }
            $uuid = Uuid::fromString($match[1])->toRfc4122();
            if (isset($ids[$uuid])) {
                return new JsonResponse(['error' => sprintf('Duplicate CustomFieldDefinition IRI: %s', $iri)], 400);
            }
            $ids[$uuid] = true;
        }

        // Field order is space-level now; reorder the project's space's fields.
        $definitions = $this->em->getRepository(CustomFieldDefinition::class)
            ->findBy(['space' => $project->getSpace()]);
        $byId = [];
        foreach ($definitions as $def) {
            $byId[(string) $def->getId()] = $def;
        }

        // Walk the input in order, renumbering each definition. Every
        // definition must appear exactly once — otherwise positions would
        // be left with gaps or duplicates.
        $position = 0;
        $seen = [];
        foreach (array_keys($ids) as $uuid) {
            if (!isset($byId[$uuid])) {
                return new JsonResponse(
                    ['error' => sprintf('CustomFieldDefinition /custom_field_definitions/%s is not part of this space.', $uuid)],
                    400,
                );
            }
            $byId[$uuid]->setPosition($position++);
            $seen[$uuid] = true;
        }

        if (count($seen) !== count($byId)) {
            return new JsonResponse(
                ['error' => 'Reorder payload must list every custom field of this project exactly once.'],
                400,
            );
        }

        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    private function canAdmin(Project $project, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        // Fields are editable by any space member for now (#custom-fields-space).
        return $project->isAccessibleBy($user);
    }
}
