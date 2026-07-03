<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\TimeEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Time tracking (#444): create/list scoping, server-side owner stamping, derived
 * duration, the running-timer lifecycle, and validation.
 */
class TimeEntryTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\TimeEntry')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testMemberTracksTimeAndDurationIsDerived(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $spaceIri = $this->spaceIri($project);

        $client = static::createClient();
        $client->loginUser($alice);

        $entry = $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'project' => '/projects/' . $project->getId(),
                'description' => 'Wiring the timer',
                'startedAt' => '2026-07-03T09:00:00+00:00',
                'endedAt' => '2026-07-03T10:30:00+00:00',
                'billable' => true,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $this->assertResponseStatusCodeSame(201);
        // 1h30m = 5400s, derived server-side.
        $this->assertSame(5400, $entry['durationSeconds'] ?? null);

        // Owner stamped server-side, not from the payload.
        $row = $this->entityManager->getRepository(TimeEntry::class)
            ->findOneBy(['description' => 'Wiring the timer']);
        $this->assertNotNull($row);
        $this->assertSame((string) $alice->getId(), (string) $row->getUser()?->getId());

        $list = $client->request('GET', '/time_entries?space=' . $spaceIri)->toArray();
        $this->assertSame(1, $list['totalItems'] ?? null);
    }

    public function testNonMemberCannotSeeEntries(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $project = $this->createProject($alice, 'Private');
        $spaceIri = $this->spaceIri($project);

        $client = static::createClient();
        $client->loginUser($alice);
        $created = $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'startedAt' => '2026-07-03T09:00:00+00:00',
                'endedAt' => '2026-07-03T09:30:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $iri = $created['@id'];
        $this->assertIsString($iri);

        $client->loginUser($stranger);
        $list = $client->request('GET', '/time_entries?space=' . $spaceIri)->toArray();
        $this->assertSame(0, $list['totalItems'] ?? null);

        $client->request('GET', $iri);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRunningTimerHasNoDurationUntilStopped(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $spaceIri = $this->spaceIri($project);

        $client = static::createClient();
        $client->loginUser($alice);

        // Start: no endedAt → running, no duration.
        $running = $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'startedAt' => '2026-07-03T09:00:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        // Running timer: no duration yet (serialized as null or omitted).
        $this->assertNull($running['durationSeconds'] ?? null);
        $iri = $running['@id'];
        $this->assertIsString($iri);

        // Stop: patch endedAt → duration derived.
        $stopped = $client->request('PATCH', $iri, [
            'json' => ['endedAt' => '2026-07-03T09:15:00+00:00'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame(900, $stopped['durationSeconds'] ?? null);
    }

    public function testStartingASecondTimerStopsTheFirst(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $spaceIri = $this->spaceIri($project);

        $client = static::createClient();
        $client->loginUser($alice);

        $first = $client->request('POST', '/time_entries', [
            'json' => ['space' => $spaceIri, 'startedAt' => '2026-07-03T09:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $firstIri = $first['@id'];
        $this->assertIsString($firstIri);

        // Starting a second running timer must not violate the one-running-per-user
        // invariant — the first is auto-stopped.
        $client->request('POST', '/time_entries', [
            'json' => ['space' => $spaceIri, 'startedAt' => '2026-07-03T11:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // The first now has an end time; exactly one running entry remains.
        $first = $client->request('GET', $firstIri)->toArray();
        $this->assertNotNull($first['endedAt'] ?? null);

        $running = $client->request('GET', '/time_entries?space=' . $spaceIri . '&exists[endedAt]=false')
            ->toArray();
        $this->assertSame(1, $running['totalItems'] ?? null);
    }

    public function testEndBeforeStartIsRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $spaceIri = $this->spaceIri($project);

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'startedAt' => '2026-07-03T10:00:00+00:00',
                'endedAt' => '2026-07-03T09:00:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    private function spaceIri(Project $project): string
    {
        $space = $project->getSpace();
        assert($space instanceof Space);

        return '/spaces/' . $space->getId();
    }

    private function createProject(User $owner, string $title): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        $this->addProjectMember($project, $owner);
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
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
