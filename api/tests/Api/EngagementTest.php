<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Engagement;
use App\Entity\Client;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Engagements (Harvest model): admin-managed (invoices-gated) CRUD with
 * embedded categories, and assigning task-management boards to them.
 */
class EngagementTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\TimeEntry')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\EngagementCategory')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Engagement')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAdminCreatesEngagementWithCategories(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'EUR'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $body = $client->request('POST', '/engagements', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'name' => 'Website',
                'categories' => [
                    ['name' => 'Design', 'rateAmount' => 9000, 'position' => 0],
                    ['name' => 'Dev', 'rateAmount' => 12000, 'position' => 1],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('Website', $body['name'] ?? null);
        // Currency defaulted from the client.
        $this->assertSame('EUR', $body['currency'] ?? null);
        $categories = $body['categories'] ?? [];
        $this->assertIsArray($categories);
        $this->assertCount(2, $categories);
    }

    public function testMemberWithoutInvoiceRoleCannotCreate(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $spaceIri = '/spaces/' . $space->getId();

        $adminClient = static::createClient();
        $adminClient->loginUser($admin);
        $clientRow = $adminClient->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $adminClient->loginUser($member);
        $adminClient->request('POST', '/engagements', [
            'json' => ['space' => $spaceIri, 'client' => $clientRow['@id'], 'name' => 'Sneaky'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAssignProjectsToEngagement(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();

        $taskProject = (new Board())->setOwner($admin)->setTitle('App')->setSpace($space);
        $this->entityManager->persist($taskProject);
        $client = (new Client())->setSpace($space)->setName('Acme')->setCreatedBy($admin);
        $this->entityManager->persist($client);
        $engagement = (new Engagement())->setSpace($space)->setClient($client)->setName('Website')->setCreatedBy($admin);
        $this->entityManager->persist($engagement);
        $this->entityManager->flush();

        $httpClient = static::createClient();
        $httpClient->loginUser($admin);
        $httpClient->request('PUT', '/engagements/' . $engagement->getId() . '/boards', [
            'json' => ['boards' => ['/boards/' . $taskProject->getId()]],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Board::class)->find($taskProject->getId());
        $this->assertInstanceOf(Board::class, $reloaded);
        $this->assertSame((string) $engagement->getId(), (string) $reloaded->getEngagement()?->getId());
    }

    private function createSharedSpace(User $admin, ?User $member = null): Space
    {
        $space = (new Space())->setName('Studio')->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $adminMembership = (new SpaceMembership())->setUser($admin)->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($adminMembership);
        $this->entityManager->persist($adminMembership);
        if (null !== $member) {
            $memberMembership = (new SpaceMembership())->setUser($member)->setRole(Space::ROLE_MEMBER);
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
