<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\TimeEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Per-person + cost rates (#653): engagement person-rate overrides win over
 * the category rate, space-level cost rates snapshot onto entries, and the
 * time-summary report surfaces cost + profit.
 */
class PersonCostRateTest extends ApiTestCase
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\EngagementUserRate')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\EngagementCategory')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Engagement')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testPersonRateOverrideCostSnapshotAndProfitReport(): void
    {
        $admin = $this->createUser('admin@example.com', 'Alice');
        $member = $this->createUser('member@example.com', 'Bob');
        $space = $this->createSharedSpace($admin, $member);
        $spaceId = (string) $space->getId();
        $spaceIri = '/spaces/' . $spaceId;
        $memberIri = '/users/' . $member->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        // Dev bills at 6000/h; Bob has a 9000/h person-rate override.
        $engagement = $client->request('POST', '/engagements', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'name' => 'Acme Website',
                'currency' => 'USD',
                'categories' => [['name' => 'Dev', 'rateAmount' => 6000, 'position' => 0]],
                'userRates' => [['user' => $memberIri, 'rateAmount' => 9000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $engagementIri = $engagement['@id'];
        $this->assertIsString($engagementIri);
        $rates = $engagement['userRates'] ?? null;
        $this->assertIsArray($rates);
        $this->assertCount(1, $rates);
        $categories = $engagement['categories'] ?? null;
        $this->assertIsArray($categories);
        $firstCategory = $categories[0];
        $this->assertIsArray($firstCategory);
        $categoryIri = $firstCategory['@id'];
        $this->assertIsString($categoryIri);

        // Bob's space-level cost rate: 4000/h, set via the membership PATCH.
        $membership = $this->entityManager->getRepository(SpaceMembership::class)
            ->findOneBy(['space' => $space, 'user' => $member]);
        $this->assertInstanceOf(SpaceMembership::class, $membership);
        $client->request('PATCH', '/spaces/' . $spaceId . '/members/' . $membership->getId(), [
            'json' => ['costRateAmount' => 4000],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        // Bob tracks 1h: bills at his 9000 override, costs 4000.
        $client->loginUser($member);
        $bobEntry = $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'engagement' => $engagementIri,
                'category' => $categoryIri,
                'startedAt' => '2026-07-10T09:00:00+00:00',
                'endedAt' => '2026-07-10T10:00:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(9000, $bobEntry['rateAmount'] ?? null);

        // The cost snapshot isn't client-visible — check it on the entity.
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $bobEntryId = $bobEntry['id'];
        $this->assertIsString($bobEntryId);
        $entity = $em->getRepository(TimeEntry::class)->find($bobEntryId);
        $this->assertInstanceOf(TimeEntry::class, $entity);
        $this->assertSame(4000, $entity->getCostRateAmount());

        // Alice tracks 30m: category rate, no cost rate set.
        $client->loginUser($admin);
        $aliceEntry = $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'engagement' => $engagementIri,
                'category' => $categoryIri,
                'startedAt' => '2026-07-10T11:00:00+00:00',
                'endedAt' => '2026-07-10T11:30:00+00:00',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertSame(6000, $aliceEntry['rateAmount'] ?? null);

        // Profitability by person: Bob 9000 − 4000 = 5000; Alice 3000 − 0.
        $report = $client->request('GET', '/spaces/' . $spaceId . '/reports/time-summary?groupBy=person')->toArray();
        $this->assertResponseStatusCodeSame(200);
        $rows = $report['rows'];
        $this->assertIsArray($rows);
        $byLabel = [];
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $label = $row['label'];
            $this->assertIsString($label);
            $byLabel[$label] = $row;
        }
        $bob = $byLabel['Bob User'] ?? null;
        $this->assertIsArray($bob);
        $this->assertSame(['USD' => 9000], $bob['amounts'] ?? null);
        $this->assertSame(['USD' => 4000], $bob['costs'] ?? null);
        $this->assertSame(['USD' => 5000], $bob['profit'] ?? null);
        $alice = $byLabel['Alice User'] ?? null;
        $this->assertIsArray($alice);
        $this->assertSame(['USD' => 3000], $alice['amounts'] ?? null);
        $this->assertSame([], $alice['costs'] ?? null);
        $this->assertSame(['USD' => 3000], $alice['profit'] ?? null);

        // An invalid cost rate is rejected.
        $client->request('PATCH', '/spaces/' . $spaceId . '/members/' . ($membership->getId() ?? ''), [
            'json' => ['costRateAmount' => -5],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
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
