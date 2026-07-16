<?php

namespace App\Controller;

use App\Entity\Space;
use App\Entity\TimeEntry;
use App\Entity\TimesheetSubmission;
use App\Entity\User;
use App\Repository\TimesheetSubmissionRepository;
use App\Service\TimesheetMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Weekly timesheet approvals (#654):
 *
 *  - submit: a member submits their own week — entries in it freeze until a
 *    decision (pending/approved lock, rejection unlocks). Re-submitting a
 *    rejected week flips it back to pending.
 *  - list: members see their own submissions; space admins see everyone's
 *    (the approvals queue), each row carrying the week's tracked total.
 *  - approve / reject: space admins decide; a note (most useful on
 *    rejection) is emailed back to the member.
 */
class TimesheetController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TimesheetSubmissionRepository $submissions,
        private TimesheetMailer $mailer,
    ) {
    }

    #[Route('/spaces/{id}/timesheets/submit', name: 'timesheet_submit', methods: ['POST'])]
    public function submit(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->memberSpaceOr($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }
        assert($user instanceof User);

        $payload = $request->toArray();
        $raw = $payload['weekStart'] ?? null;
        if (!is_string($raw) || '' === trim($raw)) {
            return $this->json(['error' => 'weekStart (YYYY-MM-DD) is required.'], 422);
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw), new \DateTimeZone('UTC'));
        if (false === $parsed) {
            return $this->json(['error' => 'weekStart must be formatted YYYY-MM-DD.'], 422);
        }
        $weekStart = TimesheetSubmissionRepository::weekStartOf($parsed);

        $submission = $this->submissions->findForWeek($space, $user, $weekStart);
        if (null !== $submission && TimesheetSubmission::STATUS_REJECTED !== $submission->getStatus()) {
            return $this->json(['error' => 'This week has already been submitted.'], 409);
        }
        if (null === $submission) {
            $submission = (new TimesheetSubmission())
                ->setSpace($space)
                ->setUser($user)
                ->setWeekStart($weekStart);
            $this->em->persist($submission);
        } else {
            // Re-submission after a rejection: back to pending, fresh clock.
            $submission->setStatus(TimesheetSubmission::STATUS_PENDING);
            $submission->setSubmittedAt(new \DateTimeImmutable());
            $submission->setDecidedAt(null);
            $submission->setDecidedBy(null);
            $submission->setNote(null);
        }
        $this->em->flush();

        $this->mailer->sendSubmitted($submission);

        return $this->json($this->row($submission), 201);
    }

    #[Route('/spaces/{id}/timesheets', name: 'timesheet_list', methods: ['GET'])]
    public function list(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->memberSpaceOr($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }
        assert($user instanceof User);

        $status = $request->query->get('status');
        if (null !== $status && !in_array($status, TimesheetSubmission::STATUSES, true)) {
            return $this->json(['error' => 'Unknown status.'], 422);
        }

        $rows = [];
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $space->isAdmin($user);
        foreach ($this->submissions->findForSpace($space, $status) as $submission) {
            // Members only see their own submissions; admins see the queue.
            if (!$isAdmin && true !== $submission->getUser()?->getId()?->equals($user->getId())) {
                continue;
            }
            $rows[] = $this->row($submission);
        }

        return $this->json(['rows' => $rows]);
    }

    #[Route('/timesheets/{id}/approve', name: 'timesheet_approve', methods: ['POST'])]
    public function approve(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->decide($id, $request, $user, TimesheetSubmission::STATUS_APPROVED);
    }

    #[Route('/timesheets/{id}/reject', name: 'timesheet_reject', methods: ['POST'])]
    public function reject(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->decide($id, $request, $user, TimesheetSubmission::STATUS_REJECTED);
    }

    private function decide(string $id, Request $request, ?User $user, string $status): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Submission not found.'], 404);
        }
        $submission = $this->submissions->find($id);
        $space = $submission?->getSpace();
        if (
            null === $submission || null === $space
            || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))
        ) {
            return $this->json(['error' => 'Submission not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$space->isAdmin($user)) {
            return $this->json(['error' => 'Only space admins can review timesheets.'], 403);
        }
        if (TimesheetSubmission::STATUS_PENDING !== $submission->getStatus()) {
            return $this->json(['error' => 'This submission has already been decided.'], 409);
        }

        $payload = $request->toArray();
        $note = $payload['note'] ?? null;
        $submission->setStatus($status);
        $submission->setDecidedAt(new \DateTimeImmutable());
        $submission->setDecidedBy($user);
        $submission->setNote(
            is_string($note) && '' !== trim($note)
                ? mb_substr(trim($note), 0, TimesheetSubmission::MAX_NOTE_LENGTH)
                : null,
        );
        $this->em->flush();

        $this->mailer->sendDecision($submission);

        return $this->json($this->row($submission));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(TimesheetSubmission $submission): array
    {
        $member = $submission->getUser();
        $name = null === $member
            ? ''
            : trim($member->getGivenName() . ' ' . $member->getFamilyName());

        return [
            'id' => (string) $submission->getId(),
            'user' => [
                '@id' => '/users/' . $member?->getId(),
                'name' => '' !== $name ? $name : ($member?->getEmail() ?? ''),
            ],
            'weekStart' => $submission->getWeekStart()?->format('Y-m-d'),
            'status' => $submission->getStatus(),
            'submittedAt' => $submission->getSubmittedAt()->format(\DateTimeInterface::ATOM),
            'decidedAt' => $submission->getDecidedAt()?->format(\DateTimeInterface::ATOM),
            'note' => $submission->getNote(),
            'totalSeconds' => $this->weekSeconds($submission),
        ];
    }

    /** Total tracked seconds in the submission's week (the queue's context). */
    private function weekSeconds(TimesheetSubmission $submission): int
    {
        $weekStart = $submission->getWeekStart();
        $space = $submission->getSpace();
        $member = $submission->getUser();
        if (null === $weekStart || null === $space || null === $member) {
            return 0;
        }

        $total = $this->em->createQuery(
            'SELECT COALESCE(SUM(t.durationSeconds), 0) FROM ' . TimeEntry::class . ' t
             WHERE t.space = :space AND t.user = :user AND t.endedAt IS NOT NULL
               AND t.startedAt >= :from AND t.startedAt < :to',
        )
            ->setParameter('space', $space)
            ->setParameter('user', $member)
            ->setParameter('from', $weekStart)
            ->setParameter('to', $weekStart->modify('+7 days'))
            ->getSingleScalarResult();

        return (int) $total;
    }

    private function memberSpaceOr(string $id, ?User $user): Space|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        return $space;
    }
}
