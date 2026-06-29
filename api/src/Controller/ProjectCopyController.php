<?php

namespace App\Controller;

use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\ProjectFieldVisibility;
use App\Entity\Space;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\ProjectFieldVisibilityRepository;
use App\Service\CopyTitleSuffixer;
use App\Service\SpaceIriResolver;
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
 * Scope:
 *  - The project metadata (title, description) carries over. Title gets
 *    a " (copy)" suffix so the source and clone are distinguishable at
 *    a glance.
 *  - Custom fields are space-owned now (#custom-fields-space); the copy keeps
 *    the source's field SELECTION (same-space: the same definitions;
 *    cross-space: reuse-or-clone same-named fields in the target space).
 *  - The clone's `createdBy` is the current user — not the source's
 *    owner. Fresh audit history.
 *  - Attachments are NOT copied (per-row authorship + binary storage
 *    that doesn't transplant cleanly).
 *  - Tasks: opt-in via `includeTasks: true` on the body. When opted in,
 *    each task is cloned with title + description + dueDate +
 *    recurrenceRule + position + tags (only tags the caller owns).
 *    Owner becomes the caller; assignees, reminders, completedOn, and
 *    custom field values are deliberately dropped — those are
 *    user-specific state, and CFV would need an old-CFD→new-CFD
 *    mapping that's a heavier slice on its own.
 *  - Discussions live at the space level — not project — so they're
 *    never carried along on a project copy. Use
 *    {@see DiscussionCopyController} to clone a thread independently.
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
        private ProjectFieldVisibilityRepository $fieldVisibility,
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

        $payload = $request->toArray();
        $rawSpace = $payload['space'] ?? null;
        $target = $source->getSpace();
        if (is_string($rawSpace) && '' !== trim($rawSpace)) {
            $spaceId = SpaceIriResolver::extractId($rawSpace);
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
            ->setTitle(CopyTitleSuffixer::apply($source->getTitle(), self::TITLE_MAX))
            ->setDescription($source->getDescription())
            ->setOwner($user)
            ->setSpace($target);

        // Persist the project first so its id is generated and child
        // CFDs can reference it.
        $this->em->persist($copy);
        $this->em->flush();

        // Carry the source project's field SELECTION onto the copy. Fields are
        // space-owned now (#custom-fields-space): a same-space copy opts into
        // the same definitions; a cross-space copy reuses a same-named field in
        // the target space or clones it there.
        // Per-project visibility (#custom-fields-project) carries over too: the
        // clone keeps the source's per-project visibility for each field.
        $sourceVisibility = $this->fieldVisibility->visibilityMapForProject($source);
        foreach ($source->getCustomFieldDefinitions() as $sourceDefinition) {
            $effective = $sourceVisibility[(string) $sourceDefinition->getId()]
                ?? $sourceDefinition->getVisibility();

            $sameSpace = (string) $target->getId()
                === (string) $sourceDefinition->getSpace()?->getId();
            if ($sameSpace) {
                $copy->addCustomFieldDefinition($sourceDefinition);
                $this->copyFieldVisibility($copy, $sourceDefinition, $effective);
                continue;
            }
            $existing = $this->em->getRepository(CustomFieldDefinition::class)
                ->findOneBy(['space' => $target, 'name' => $sourceDefinition->getName()]);
            if (null !== $existing) {
                $copy->addCustomFieldDefinition($existing);
                $this->copyFieldVisibility($copy, $existing, $effective);
                continue;
            }
            $clone = (new CustomFieldDefinition())
                ->setSpace($target)
                ->setName($sourceDefinition->getName())
                ->setKind($sourceDefinition->getKind())
                ->setSubtype($sourceDefinition->getSubtype())
                ->setConfig($sourceDefinition->getConfig())
                ->setFooter($sourceDefinition->getFooter())
                ->setPosition($sourceDefinition->getPosition())
                ->setNullable($sourceDefinition->isNullable())
                ->setVisibility($effective);
            $this->em->persist($clone);
            $copy->addCustomFieldDefinition($clone);
            $this->copyFieldVisibility($copy, $clone, $effective);
        }

        // Optional task clone (#182 deep-copy). Opt-in via
        // `includeTasks: true` so the default copy stays metadata +
        // schema only. The clone carries forward the structural
        // bits of each task — title, description, due date,
        // recurrence rule, position — and reuses tags the COPIER
        // owns (tags are user-scoped; carrying over a tag the
        // copier doesn't own would attach a foreign row to their
        // task). User-specific state (owner, assignees, reminders,
        // completedOn) and custom field values are deliberately
        // dropped: assignees / reminders are per-individual,
        // completedOn would lie about the clone's history, and CFV
        // would need an old-CFD→new-CFD mapping that's a heavier
        // slice in its own right.
        $tasksCloned = 0;
        if (true === ($payload['includeTasks'] ?? false)) {
            $sourceTasks = $this->em->getRepository(Task::class)
                ->findBy(['project' => $source], ['position' => 'ASC']);
            foreach ($sourceTasks as $sourceTask) {
                $cloneTask = (new Task())
                    ->setOwner($user)
                    ->setProject($copy)
                    ->setTitle($sourceTask->getTitle())
                    ->setDescription($sourceTask->getDescription())
                    ->setDueDate($sourceTask->getDueDate())
                    ->setRecurrenceRule($sourceTask->getRecurrenceRule())
                    ->setPosition($sourceTask->getPosition());
                foreach ($sourceTask->getTags() as $tag) {
                    if (true === $tag->getOwner()?->getId()?->equals($user->getId())) {
                        $cloneTask->addTag($tag);
                    }
                }
                $this->em->persist($cloneTask);
                ++$tasksCloned;
            }
        }

        $this->em->flush();

        return $this->json([
            '@id' => '/projects/' . $copy->getId(),
            'id' => (string) $copy->getId(),
            'title' => $copy->getTitle(),
            'space' => '/spaces/' . $target->getId(),
            'tasksCloned' => $tasksCloned,
        ], 201);
    }

    private function copyFieldVisibility(
        Project $project,
        CustomFieldDefinition $definition,
        string $visibility,
    ): void {
        $row = (new ProjectFieldVisibility())
            ->setProject($project)
            ->setDefinition($definition)
            ->setVisibility($visibility);
        $this->em->persist($row);
    }

    /** Project's `title` column width — the clone must fit it after suffixing. */
    private const TITLE_MAX = 255;
}
