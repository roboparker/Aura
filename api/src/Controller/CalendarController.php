<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Space;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use App\Service\RecurrenceCalculator;
use App\Service\SpaceIriResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /calendar?space={iri}&start={Y-m-d}&end={Y-m-d}` — the flat list of
 * calendar entries for a space's due-dated tasks over a date window. It is the
 * single source of truth for what the calendar renders (issue #442):
 *
 *  - Non-recurring tasks contribute one entry on their due date.
 *  - An *incomplete* recurring task projects its whole series across the
 *    window via {@see RecurrenceCalculator::occurrencesInRange} (minus its
 *    EXDATE `recurrenceExceptions`) — the model materialises the next instance
 *    only on completion, so the calendar projects the upcoming ones.
 *  - A *completed* recurring task contributes just its single done occurrence;
 *    its incomplete successor (spawned on completion) projects the rest, so the
 *    series is never double-counted.
 *
 * Scope mirrors the rest of the app: tasks whose project belongs to the space,
 * plus the caller's own standalone tasks when the space is their personal one.
 * The caller must be a member of the space (admins bypass); the window is
 * capped so a pathological range can't blow up the projection.
 */
final class CalendarController extends AbstractController
{
    /** Widest window we'll project (a 6-week month grid is 42 days). */
    private const MAX_RANGE_DAYS = 62;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecurrenceCalculator $recurrence,
    ) {
    }

    #[Route('/calendar', name: 'calendar', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $rawSpace = $request->query->getString('space');
        $spaceId = '' === $rawSpace ? null : SpaceIriResolver::extractId($rawSpace);
        if (null === $spaceId || !Uuid::isValid($spaceId)) {
            return $this->json(['error' => 'A valid `space` is required.'], 400);
        }
        $space = $this->em->getRepository(Space::class)->find($spaceId);
        if (null === $space) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user)) {
            // Existence-hiding shape, matching the access extensions.
            return $this->json(['error' => 'Space not found.'], 404);
        }

        $rangeStart = $this->parseDay($request->query->getString('start'), false);
        $rangeEnd = $this->parseDay($request->query->getString('end'), true);
        if (null === $rangeStart || null === $rangeEnd || $rangeEnd < $rangeStart) {
            return $this->json(['error' => 'Valid `start` and `end` (Y-m-d) are required.'], 400);
        }
        if ($rangeStart->diff($rangeEnd)->days > self::MAX_RANGE_DAYS) {
            return $this->json(['error' => 'Requested range is too large.'], 400);
        }

        $tasks = $this->fetchTasks($space, $user, $rangeStart, $rangeEnd);

        $entries = [];
        foreach ($tasks as $task) {
            $due = $task->getDueDate();
            if (null === $due) {
                continue;
            }
            $rule = $task->getRecurrenceRule();
            $isLiveSeries = null !== $rule && null === $task->getCompletedOn();

            if (!$isLiveSeries) {
                if ($due >= $rangeStart && $due <= $rangeEnd) {
                    $entries[] = $this->entry($task, $due, false);
                }
                continue;
            }

            $occurrences = $this->recurrence->occurrencesInRange(
                $due,
                $rule,
                $rangeStart,
                $rangeEnd,
                $task->getRecurrenceExceptions(),
            );
            foreach ($occurrences as $occurrence) {
                $entries[] = $this->entry($task, $occurrence, true);
            }
        }

        return $this->json(['entries' => $entries]);
    }

    /**
     * Due-dated tasks in scope for the window: anything that could contribute
     * an occurrence — a recurring anchor on/before the end, or a non-recurring
     * task inside the window.
     *
     * @return list<Task>
     */
    private function fetchTasks(
        Space $space,
        User $user,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
    ): array {
        $qb = $this->em->getRepository(Task::class)->createQueryBuilder('t')
            ->leftJoin('t.project', 'p')
            ->andWhere('t.dueDate IS NOT NULL')
            ->andWhere('t.dueDate <= :end')
            ->andWhere('t.recurrenceRule IS NOT NULL OR t.dueDate >= :start')
            ->setParameter('start', $rangeStart)
            ->setParameter('end', $rangeEnd)
            ->setParameter('space', $space);

        // Standalone (project-less) tasks are owner-only and only belong on the
        // owner's personal-space calendar.
        $personal = $space->getIsPersonal()
            && true === $space->getCreatedBy()?->getId()?->equals($user->getId());
        if ($personal) {
            $qb->andWhere('p.space = :space OR (t.project IS NULL AND t.owner = :owner)')
                ->setParameter('owner', $user);
        } else {
            $qb->andWhere('p.space = :space');
        }

        /** @var list<Task> $tasks */
        $tasks = $qb->getQuery()->getResult();

        return $tasks;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(Task $task, \DateTimeImmutable $occurrence, bool $recurring): array
    {
        $project = $task->getProject();

        $assignees = [];
        foreach ($task->getAssignees() as $assignee) {
            $assignees[] = $this->serializeUser($assignee);
        }
        $tags = [];
        foreach ($task->getTags() as $tag) {
            $tags[] = $this->serializeTag($tag);
        }

        return [
            'taskId' => (string) $task->getId(),
            '@id' => '/tasks/' . $task->getId(),
            'title' => $task->getTitle(),
            'occurrenceDate' => $occurrence->format('Y-m-d'),
            'dueDate' => $task->getDueDate()?->format(\DateTimeInterface::ATOM),
            'completedOn' => $task->getCompletedOn()?->format(\DateTimeInterface::ATOM),
            'recurring' => $recurring,
            'project' => null === $project ? null : [
                '@id' => '/projects/' . $project->getId(),
                'id' => (string) $project->getId(),
                'title' => $project->getTitle(),
            ],
            'assignees' => $assignees,
            'tags' => $tags,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            '@id' => '/users/' . $user->getId(),
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'givenName' => $user->getGivenName(),
            'familyName' => $user->getFamilyName(),
            'nickname' => $user->getNickname(),
            'personalizedColor' => $user->getPersonalizedColor(),
            'avatarUrls' => $user->getAvatarUrls(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTag(Tag $tag): array
    {
        return [
            '@id' => '/tags/' . $tag->getId(),
            'id' => (string) $tag->getId(),
            'title' => $tag->getTitle(),
            'color' => $tag->getColor(),
        ];
    }

    private function parseDay(string $value, bool $endOfDay): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date;
    }
}
