<?php

namespace App\Controller;

use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Deep-clones a project into a target space (#182). Useful as a
 * "duplicate this project as a template" workflow.
 *
 * Scope (v1):
 *  - The project metadata (title, description) carries over. Title gets
 *    a " (copy)" suffix so the source and clone are distinguishable at
 *    a glance.
 *  - Every `CustomFieldDefinition` attached to the source is cloned into
 *    the new project (name, type, options, position, required). The
 *    schema is the most reusable part of a project, so this is the
 *    primary thing copy buys you.
 *  - The clone's `createdBy` is the current user — not the source's
 *    owner. Fresh audit history.
 *  - Tasks, discussions, comments, attachments, and tags are NOT
 *    copied. Those carry per-row authorship + conversational state
 *    that doesn't transplant cleanly; copying them lands in a
 *    follow-up slice if/when the use case shows up.
 *
 * Auth bar: caller must be able to read the source (membership in
 * its space) AND be a member of the target space. Target defaults
 * to the source's space when no `space` is supplied on the body, so
 * "copy here" is a one-click operation.
 */
class ProjectCopyController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/projects/{id}/copy', name: 'project_copy', methods: ['POST'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

        $source = $this->em->getRepository(Project::class)->find($id);
        if (null === $source) {
            return $this->json(['error' => 'Project not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$source->isAccessibleBy($user)) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $rawSpace = $payload['space'] ?? null;
        $target = $source->getSpace();
        if (is_string($rawSpace) && '' !== trim($rawSpace)) {
            $spaceId = $this->extractIdFromIri($rawSpace);
            if (null === $spaceId) {
                return $this->json(['error' => 'Invalid space IRI.'], 400);
            }
            $target = $this->em->getRepository(Space::class)->find($spaceId);
            if (null === $target) {
                return $this->json(['error' => 'Target space not found.'], 404);
            }
            if (!$this->isGranted('ROLE_ADMIN') && !$target->hasMember($user)) {
                // Hide target existence behind a 404 to match the access
                // extension's existence-hiding shape.
                return $this->json(['error' => 'Target space not found.'], 404);
            }
        } elseif (null === $target) {
            // Defensive: every persisted project has a space, but if
            // somehow not, we can't materialise a copy without one.
            return $this->json(['error' => 'Source project has no space and no target supplied.'], 400);
        }

        $copy = (new Project())
            ->setTitle($this->copyTitle($source->getTitle()))
            ->setDescription($source->getDescription())
            ->setOwner($user)
            ->setSpace($target);

        // Persist the project first so its id is generated and child
        // CFDs can reference it.
        $this->em->persist($copy);
        $this->em->flush();

        // Clone every custom field definition into the new project.
        // syncSpaceFromProject only fires when space is null at
        // PrePersist; we set it explicitly here to mirror the existing
        // create paths.
        foreach ($this->em->getRepository(CustomFieldDefinition::class)
            ->findBy(['project' => $source]) as $sourceDefinition) {
            $clone = (new CustomFieldDefinition())
                ->setProject($copy)
                ->setSpace($target)
                ->setName($sourceDefinition->getName())
                ->setType($sourceDefinition->getType())
                ->setOptions($sourceDefinition->getOptions())
                ->setPosition($sourceDefinition->getPosition())
                ->setRequired($sourceDefinition->isRequired());
            $this->em->persist($clone);
        }

        $this->em->flush();

        return $this->json([
            '@id' => '/projects/' . $copy->getId(),
            'id' => (string) $copy->getId(),
            'title' => $copy->getTitle(),
            'space' => '/spaces/' . $target->getId(),
        ], 201);
    }

    /**
     * "<title> (copy)" — but only adds the suffix if it isn't already
     * present so repeated copies don't pile up "(copy) (copy) (copy)".
     * Truncates to fit Project's 255-char `title` column so the new
     * row doesn't fail validation.
     */
    private const TITLE_MAX = 255;

    private function copyTitle(string $sourceTitle): string
    {
        $suffix = ' (copy)';
        if (str_ends_with($sourceTitle, $suffix)) {
            return $sourceTitle;
        }
        $combined = $sourceTitle . $suffix;
        if (mb_strlen($combined) <= self::TITLE_MAX) {
            return $combined;
        }
        $room = self::TITLE_MAX - mb_strlen($suffix);
        if ($room <= 0) {
            return mb_substr($sourceTitle, 0, self::TITLE_MAX);
        }
        return mb_substr($sourceTitle, 0, $room) . $suffix;
    }

    private function extractIdFromIri(string $iri): ?string
    {
        $trimmed = trim($iri);
        if (Uuid::isValid($trimmed)) {
            return $trimmed;
        }
        if (1 === preg_match('#^/spaces/([0-9a-f-]+)$#i', $trimmed, $m) && Uuid::isValid($m[1])) {
            return $m[1];
        }
        return null;
    }
}
