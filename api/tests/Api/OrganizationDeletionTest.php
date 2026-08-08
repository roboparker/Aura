<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Organization;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\OrganizationDeletionService;
use App\Tests\Billing\InMemoryStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Organization deletion (#billing Phase 1c) — the grace period is the feature.
 *
 * Deleting an org cascades to every space it owns, so these tests are mostly
 * about what *doesn't* happen: nothing is destroyed until the window lapses,
 * an owner can reverse it, and a non-owner can't start it at all.
 */
class OrganizationDeletionTest extends ApiTestCase
{
    private const PASSWORD = 'Password123!@#';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Subscription')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CancellationFeedback')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\OrganizationMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Organization')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceRole')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();

        $this->gateway()->reset();
    }

    public function testOwnerSchedulesDeletionAndTheOrgLeavesTheListing(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);

        $client = static::createClient();
        $client->loginUser($owner);

        $body = $this->deleteOrg($client, $org)->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame('scheduled', $body['status']);
        $this->assertNotNull($body['purgeAfter']);

        // Gone from the listing, but still on disk.
        $list = $client->request('GET', '/organizations')->toArray();
        $this->assertSame(0, $list['totalItems']);
        $this->entityManager->clear();
        $this->assertNotNull($this->find($org), 'soft delete must not remove the row');
    }

    public function testDeletionHidesTheOrgsSpaces(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);

        $client = static::createClient();
        $client->loginUser($owner);
        $this->makeOrgSpace($client, $org);

        $before = $client->request('GET', '/spaces')->toArray();
        $this->assertSame(1, $before['totalItems']);

        $this->deleteOrg($client, $org);

        // The grace period only means something if members stop working in the
        // thing that's scheduled to vanish.
        $after = $client->request('GET', '/spaces')->toArray();
        $this->assertSame(0, $after['totalItems'], "a deleted org's spaces must stop being reachable");
    }

    public function testRestoreBringsTheOrgAndItsSpacesBack(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);

        $client = static::createClient();
        $client->loginUser($owner);
        $this->makeOrgSpace($client, $org);
        $this->deleteOrg($client, $org);

        $client->request('POST', '/organizations/' . $org->getId() . '/restore', [
            'json' => ['currentPassword' => self::PASSWORD],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $list = $client->request('GET', '/organizations')->toArray();
        $this->assertSame(1, $list['totalItems']);

        $spaces = $client->request('GET', '/spaces')->toArray();
        $this->assertSame(1, $spaces['totalItems'], 'restore must bring the spaces back too');
    }

    public function testDeletedOrgsAreListedForTheirOwner(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);

        $client = static::createClient();
        $client->loginUser($owner);
        $this->deleteOrg($client, $org);

        // Without this the owner has no route back to the thing they need to
        // restore — it's dropped out of every other listing.
        $body = $client->request('GET', '/organizations/deleted')->toArray();
        $this->assertCount(1, $body['organizations']);
        $this->assertSame((string) $org->getId(), $body['organizations'][0]['id']);
    }

    public function testNonOwnerCannotDelete(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $admin = $this->createUser('admin@example.com');
        $org->addMember($admin, Organization::ROLE_ADMIN);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);
        $this->deleteOrg($client, $org);

        // Admins manage the org; ending it is the owner's call.
        $this->assertResponseStatusCodeSame(403);
    }

    public function testStrangerGetsNotFound(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $stranger = $this->createUser('stranger@example.com');

        $client = static::createClient();
        $client->loginUser($stranger);
        $this->deleteOrg($client, $org);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testWrongNameIsRejected(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', '/organizations/' . $org->getId() . '/delete', [
            'json' => [
                'confirmName' => 'Not The Name',
                'reason' => 'not_using',
                'currentPassword' => self::PASSWORD,
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testWrongPasswordIsRejected(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', '/organizations/' . $org->getId() . '/delete', [
            'json' => [
                'confirmName' => 'Acme',
                'reason' => 'not_using',
                'currentPassword' => 'wrong-password',
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->entityManager->clear();
        $this->assertFalse($this->find($org)?->isDeleted());
    }

    public function testMissingReasonIsRejected(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', '/organizations/' . $org->getId() . '/delete', [
            'json' => ['confirmName' => 'Acme', 'currentPassword' => self::PASSWORD],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testDeletionCancelsBillingImmediately(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $subscription = (new Subscription())
            ->setOrganization($org)
            ->setPlan('business')
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeCustomerId('cus_x')
            ->setStripeSubscriptionId('sub_bye');
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($owner);
        $this->deleteOrg($client, $org);
        $this->assertResponseIsSuccessful();

        // Nobody should pay through a grace period they asked to end.
        $this->assertContains('sub_bye', $this->gateway()->immediatelyCanceledSubscriptions);
    }

    public function testDeletedOrgStopsEntitlingItsMembers(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $subscription = (new Subscription())
            ->setOrganization($org)
            ->setPlan('business')
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeCustomerId('cus_x')
            ->setStripeSubscriptionId('sub_ent');
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($owner);
        $this->deleteOrg($client, $org);

        // The plan drops at deletion time, not when the cancellation webhook
        // eventually lands — otherwise the account is gone from every listing
        // while its members still hold its entitlements.
        $body = $client->request('GET', '/organizations/' . $org->getId() . '/billing')->toArray();
        $this->assertSame('free', $body['plan']);
    }

    public function testPurgeSkipsAnOrgStillInsideItsWindow(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);

        $client = static::createClient();
        $client->loginUser($owner);
        $this->deleteOrg($client, $org);

        $purged = $this->deletionService()->purgeDue();

        $this->assertSame(0, $purged);
        $this->entityManager->clear();
        $this->assertNotNull($this->find($org));
    }

    public function testPurgeHardDeletesOnceTheWindowLapses(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);

        $client = static::createClient();
        $client->loginUser($owner);
        $this->makeOrgSpace($client, $org);
        $this->deleteOrg($client, $org);

        // Stand at a point past the stored purgeAfter rather than rewriting the
        // row, so the test exercises the same comparison the nightly job makes.
        $purged = $this->deletionService()->purgeDue(new \DateTimeImmutable('+31 days'));

        $this->assertSame(1, $purged);
        $this->entityManager->clear();
        $this->assertNull($this->find($org), 'the org should be gone after the grace period');
    }

    public function testPurgeKeepsTheBillingHistory(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $subscription = (new Subscription())
            ->setOrganization($org)
            ->setPlan('business')
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeCustomerId('cus_x')
            ->setStripeSubscriptionId('sub_hist');
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($owner);
        $this->deleteOrg($client, $org);
        $this->deletionService()->purgeDue(new \DateTimeImmutable('+31 days'));

        // What an account paid should outlive the account.
        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(Subscription::class)->findBy(['stripeSubscriptionId' => 'sub_hist']);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->getOrganization());
    }

    private function deleteOrg(Client $client, Organization $org): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $client->request('POST', '/organizations/' . $org->getId() . '/delete', [
            'json' => [
                'confirmName' => $org->getName(),
                'reason' => 'not_using',
                'comment' => 'winding the team down',
                'currentPassword' => self::PASSWORD,
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    private function deletionService(): OrganizationDeletionService
    {
        $service = static::getContainer()->get(OrganizationDeletionService::class);
        assert($service instanceof OrganizationDeletionService);

        return $service;
    }

    private function gateway(): InMemoryStripeGateway
    {
        $gateway = static::getContainer()->get(InMemoryStripeGateway::class);
        assert($gateway instanceof InMemoryStripeGateway);

        return $gateway;
    }

    private function find(Organization $org): ?Organization
    {
        return $this->entityManager->getRepository(Organization::class)->find($org->getId());
    }

    private function makeOrgSpace(Client $client, Organization $org): string
    {
        $body = $client->request('POST', '/spaces', [
            'json' => ['name' => 'Team Space', 'organization' => '/organizations/' . $org->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $id = $body['id'] ?? null;
        $this->assertIsString($id);

        return $id;
    }

    private function makeOrg(User $owner): Organization
    {
        $org = (new Organization())->setName('Acme')->setSlug('o-' . bin2hex(random_bytes(4)))->setCreatedBy($owner);
        $org->addMember($owner, Organization::ROLE_OWNER);
        $this->entityManager->persist($org);
        $this->entityManager->flush();

        return $org;
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
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
