<?php

namespace App\Controller;

use App\Entity\CustomFieldDefinition;
use App\Entity\Discussion;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\Tag;
use App\Entity\Task;
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
 * Scope:
 *  - The project metadata (title, description) carries over. Title gets
 *    a " (copy)" suffix so the source and clone are distinguishable at
 *    a glance.
 *  - Every `CustomFieldDefinition` attached to the source is cloned into
 *    the new project (name, type, options, position, required). The
 *    schema is the most reusable part of a project, so this is the
 *    primary thing copy buys you.
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
 *  - Discussions: opt-in via `includeDiscussions: true`. Each
 *    discussion thread is cloned with title + body + category; the
 *    clone's `author` is the caller (fresh audit history). `isPinned`
 *    and `isLocked` reset to false — those are moderation state tied
 *    to the source thread, not to the content. Mirrors the v1 scope
 *    of {@see DiscussionCopyController}; discussions don't have a
 *    comments model so there's nothing else to carry.
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
                    if ($tag instanceof Tag
                        && $tag->getOwner()?->getId()?->equals($user->getId())
                    ) {
                        $cloneTask->addTag($tag);
                    }
                }
                $this->em->persist($cloneTask);
                ++$tasksCloned;
            }
        }

        // Optional discussion clone (#182 deep-copy). Opt-in via
        // `includeDiscussions: true`. Each thread's title + body +
        // category move over; the clone's author is the caller so the
        // audit history starts fresh, and pin/lock reset to false
        // because they're moderation state tied to the source thread,
        // not properties of the content itself. Same v1 scope as
        // DiscussionCopyController.
        $discussionsCloned = 0;
        if (true === ($payload['includeDiscussions'] ?? false)) {
            $sourceDiscussions = $this->em->getRepository(Discussion::class)
                ->findBy(['project' => $source], ['createdAt' => 'ASC']);
            foreach ($sourceDiscussions as $sourceDiscussion) {
                $cloneDiscussion = (new Discussion())
                    ->setProject($copy)
                    ->setSpace($target)
                    ->setAuthor($user)
                    ->setTitle($sourceDiscussion->getTitle())
                    ->setBody($sourceDiscussion->getBody())
                    ->setCategory($sourceDiscussion->getCategory());
                $this->em->persist($cloneDiscussion);
                ++$discussionsCloned;
            }
        }

        $this->em->flush();

        return $this->json([
            '@id' => '/projects/' . $copy->getId(),
            'id' => (string) $copy->getId(),
            'title' => $copy->getTitle(),
            'space' => '/spaces/' . $target->getId(),
            'tasksCloned' => $tasksCloned,
            'discussionsCloned' => $discussionsCloned,
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
