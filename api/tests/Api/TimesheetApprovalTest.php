<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Timesheet approvals (#654): submitting a week freezes its entries,
 * rejection unlocks (with a note), approval re-locks, and only space admins
 * decide.
 */
class TimesheetApprovalTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\TimesheetApproval')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\TimeEntry')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Service')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testSubmitLockRejectUnlockAndApprove(): void
    {
        $admin = $this->createUser('admin@example.com', 'Alice');
        $member = $this->createUser('member@example.com', 'Bob');
        $space = $this->createSharedSpace($admin, $member);
        $spaceId = (string) $space->getId();
        $spaceIri = '/spaces/' . $spaceId;

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $project = $client->request('POST', '/projects', [
            'json' => [
                'space' => $spaceIri,
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

        // Bob tracks 1h on Wednesday 2026-07-15 (week of Monday 2026-07-13).
        $client->loginUser($member);
        $entry = $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'project' => $projectIri,
                'category' => $categoryIri,
                'startedAt' => '2026-07-15T09:00:00+00:00',
                'endedAt' => '2026-07-15T10:00:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $entryIri = $entry['@id'];
        $this->assertIsString($entryIri);

        // Submitting any day of the week normalises to its Monday and emails
        // the space admin (one request cycle => one email counted).
        $submitted = $client->request('POST', '/spaces/' . $spaceId . '/timesheets/submit', [
            'json' => ['weekStart' => '2026-07-15'],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('2026-07-13', $submitted['weekStart'] ?? null);
        $this->assertSame('pending', $submitted['status'] ?? null);
        $this->assertSame(3600, $submitted['totalSeconds'] ?? null);
        $this->assertEmailCount(1);
        $submissionId = $submitted['id'];
        $this->assertIsString($submissionId);

        // The week is frozen: edit, create-in-week, and delete all bounce.
        $client->request('PATCH', $entryIri, [
            'json' => ['description' => 'tweak'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
        $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'project' => $projectIri,
                'category' => $categoryIri,
                'startedAt' => '2026-07-17T09:00:00+00:00',
                'endedAt' => '2026-07-17T10:00:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
        $client->request('DELETE', $entryIri);
        $this->assertResponseStatusCodeSame(422);

        // Next week is unaffected.
        $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'project' => $projectIri,
                'category' => $categoryIri,
                'startedAt' => '2026-07-21T09:00:00+00:00',
                'endedAt' => '2026-07-21T10:00:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Double-submit 409s; a member can't decide.
        $client->request('POST', '/spaces/' . $spaceId . '/timesheets/submit', [
            'json' => ['weekStart' => '2026-07-13'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);
        $client->request('POST', '/timesheets/' . $submissionId . '/approve', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        // The member's list shows only their own row.
        $mine = $client->request('GET', '/spaces/' . $spaceId . '/timesheets')->toArray();
        $mineRows = $mine['rows'] ?? null;
        $this->assertIsArray($mineRows);
        $this->assertCount(1, $mineRows);

        // Admin rejects with a note: the member is emailed, the week unlocks.
        $client->loginUser($admin);
        $rejected = $client->request('POST', '/timesheets/' . $submissionId . '/reject', [
            'json' => ['note' => 'Split Wednesday into two entries.'],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('rejected', $rejected['status'] ?? null);
        $this->assertEmailCount(1);

        $client->loginUser($member);
        $client->request('PATCH', $entryIri, [
            'json' => ['description' => 'fixed as asked'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        // Re-submit flips back to pending; admin approves; the week re-locks.
        $resubmitted = $client->request('POST', '/spaces/' . $spaceId . '/timesheets/submit', [
            'json' => ['weekStart' => '2026-07-13'],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('pending', $resubmitted['status'] ?? null);

        $client->loginUser($admin);
        $approved = $client->request('POST', '/timesheets/' . $submissionId . '/approve', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertSame('approved', $approved['status'] ?? null);

        $client->loginUser($member);
        $client->request('PATCH', $entryIri, [
            'json' => ['description' => 'too late'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        // A decided submission can't be decided again.
        $client->loginUser($admin);
        $client->request('POST', '/timesheets/' . $submissionId . '/reject', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);

        // In-app rows landed alongside the emails (#667): the admin has the
        // submission notifications (deep-linking the approvals queue), the
        // member has the decisions (deep-linking their timesheet).
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $submittedRows = $em->getRepository(\App\Entity\Notification::class)->findBy([
            'recipient' => $admin->getId(),
            'type' => \App\Entity\Notification::TYPE_TIMESHEET_SUBMITTED,
        ]);
        $this->assertCount(2, $submittedRows); // initial submit + re-submit
        $this->assertSame('/approvals', $submittedRows[0]->getTargetUrl());
        $decidedRows = $em->getRepository(\App\Entity\Notification::class)->findBy([
            'recipient' => $member->getId(),
            'type' => \App\Entity\Notification::TYPE_TIMESHEET_DECIDED,
        ]);
        $this->assertCount(2, $decidedRows); // reject + approve
        $this->assertSame('/time', $decidedRows[0]->getTargetUrl());
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

    private function createUser(string $email, string $givenName): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName($givenName);
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
