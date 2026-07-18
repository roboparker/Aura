<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Notification;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\TimesheetSubmission;
use App\Entity\User;
use App\Repository\TimesheetSubmissionRepository;
use App\Service\TimesheetNudgeDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Timesheet submission nudges (#668): members who tracked time last week
 * but haven't submitted it get one reminder — only in spaces where
 * approvals are in use, idempotent per (user, week).
 */
class TimesheetNudgeTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Notification')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\TimesheetSubmission')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\TimeEntry')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Service')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testNudgesUnsubmittedTrackersOnceAndSkipsSubmitted(): void
    {
        $now = new \DateTimeImmutable();
        $lastWeekStart = TimesheetSubmissionRepository::weekStartOf($now)->modify('-7 days');

        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $submitted = $this->createUser('diligent@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $extraMembership = (new SpaceMembership())
            ->setUser($submitted)
            ->setRole(Space::ROLE_MEMBER);
        $space->addUserMembership($extraMembership);
        $this->entityManager->persist($extraMembership);

        // Approvals are "in use" here: an older decided submission exists.
        $history = (new TimesheetSubmission())
            ->setSpace($space)
            ->setUser($admin)
            ->setWeekStart($lastWeekStart->modify('-14 days'))
            ->setStatus(TimesheetSubmission::STATUS_APPROVED);
        $this->entityManager->persist($history);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => '/spaces/' . $space->getId(), 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $project = $client->request('POST', '/projects', [
            'json' => [
                'space' => '/spaces/' . $space->getId(),
                'client' => $clientRow['@id'],
                'name' => 'Acme Website',
                'currency' => 'USD',
                'categories' => [['name' => 'Dev', 'billingRate' => 6000, 'position' => 0]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $projectIri = $project['@id'];
        $this->assertIsString($projectIri);
        $categories = $project['categories'] ?? null;
        $this->assertIsArray($categories);
        $firstCategory = $categories[0];
        $this->assertIsArray($firstCategory);
        $categoryIri = $firstCategory['@id'];
        $this->assertIsString($categoryIri);

        // Both members tracked an hour last week.
        foreach ([$member, $submitted] as $tracker) {
            $client->loginUser($tracker);
            $client->request('POST', '/time_entries', [
                'json' => [
                    'space' => '/spaces/' . $space->getId(),
                    'project' => $projectIri,
                    'category' => $categoryIri,
                    'startedAt' => $lastWeekStart->modify('+2 days')->setTime(9, 0)->format(\DateTimeInterface::ATOM),
                    'endedAt' => $lastWeekStart->modify('+2 days')->setTime(10, 0)->format(\DateTimeInterface::ATOM),
                ],
                'headers' => ['Content-Type' => 'application/ld+json'],
            ]);
            $this->assertResponseStatusCodeSame(201);
        }

        // The diligent member submits their week (pending) — after tracking,
        // since a pending submission locks the week's entries.
        $client->loginUser($submitted);
        $client->request('POST', '/spaces/' . $space->getId() . '/timesheets/submit', [
            'json' => ['weekStart' => $lastWeekStart->format('Y-m-d')],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // The submit itself emailed the admin (#667) into this container's
        // mailer logger — make one email-free request so the logger resets and
        // the counts below only see the in-process sweep sends. No HTTP
        // requests after this point.
        $client->request('GET', '/spaces/' . $space->getId() . '/timesheets');
        $this->assertResponseStatusCodeSame(200);

        $dispatcher = static::getContainer()->get(TimesheetNudgeDispatcher::class);

        // Only the unsubmitted tracker is nudged; the pending submitter isn't.
        $this->assertSame(1, $dispatcher->dispatchDue($now));
        $this->assertEmailCount(1);
        $message = $this->getMailerMessage();
        $this->assertNotNull($message);
        $this->assertEmailAddressContains($message, 'To', 'member@example.com');

        // Idempotent: the same week never nudges twice.
        $this->assertSame(0, $dispatcher->dispatchDue($now));
        $this->assertEmailCount(1);

        // The in-app row landed with the idempotency key + deep-link.
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $rows = $em->getRepository(Notification::class)->findBy([
            'recipient' => $member->getId(),
            'type' => Notification::TYPE_TIMESHEET_NUDGE,
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame('/time', $rows[0]->getTargetUrl());
    }

    public function testSpacesWithoutApprovalHistoryAreLeftAlone(): void
    {
        $now = new \DateTimeImmutable();
        $lastWeekStart = TimesheetSubmissionRepository::weekStartOf($now)->modify('-7 days');

        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => '/spaces/' . $space->getId(), 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $project = $client->request('POST', '/projects', [
            'json' => [
                'space' => '/spaces/' . $space->getId(),
                'client' => $clientRow['@id'],
                'name' => 'Acme Website',
                'currency' => 'USD',
                'categories' => [['name' => 'Dev', 'billingRate' => 6000, 'position' => 0]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $projectIri = $project['@id'];
        $this->assertIsString($projectIri);
        $categories = $project['categories'] ?? null;
        $this->assertIsArray($categories);
        $firstCategory = $categories[0];
        $this->assertIsArray($firstCategory);
        $categoryIri = $firstCategory['@id'];
        $this->assertIsString($categoryIri);

        $client->request('POST', '/time_entries', [
            'json' => [
                'space' => '/spaces/' . $space->getId(),
                'project' => $projectIri,
                'category' => $categoryIri,
                'startedAt' => $lastWeekStart->modify('+2 days')->setTime(9, 0)->format(\DateTimeInterface::ATOM),
                'endedAt' => $lastWeekStart->modify('+2 days')->setTime(10, 0)->format(\DateTimeInterface::ATOM),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Nobody in this space has ever submitted a timesheet — no nudges.
        $dispatcher = static::getContainer()->get(TimesheetNudgeDispatcher::class);
        $this->assertSame(0, $dispatcher->dispatchDue($now));
        $this->assertEmailCount(0);
    }

    private function createSharedSpace(User $admin, ?User $member = null): Space
    {
        $space = (new Space())->setName('Studio')->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $adminMembership = (new SpaceMembership())
            ->setUser($admin)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($adminMembership);
        $this->entityManager->persist($adminMembership);
        if (null !== $member) {
            $memberMembership = (new SpaceMembership())
                ->setUser($member)
                ->setRole(Space::ROLE_MEMBER);
            $space->addUserMembership($memberMembership);
            $this->entityManager->persist($memberMembership);
        }
        $this->entityManager->flush();

        return $space;
    }

    private function createUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
