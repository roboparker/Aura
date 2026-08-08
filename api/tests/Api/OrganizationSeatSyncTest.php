<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\SpaceRole;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Billing\InMemoryStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seat accounting (#billing Phase 1c): auto org-join when someone is added to
 * an org space, and the Stripe quantity that has to follow it.
 *
 * The two belong in one file because they're one behaviour: adding a member is
 * only safe to automate if the bill tracks it, and a seat count that doesn't
 * reach Stripe is a revenue leak that widens silently.
 *
 * Messenger runs sync:// under `when@test`, so a dispatch lands on the handler
 * inside the request and the gateway double records the push.
 */
class OrganizationSeatSyncTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Subscription')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\OrganizationMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Organization')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceRole')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();

        $this->gateway()->reset();
    }

    public function testSpaceMemberAutoJoinsTheOrgAsGuest(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $this->createUser('outsider@example.com');

        $client = static::createClient();
        $client->loginUser($owner);
        $space = $this->makeOrgSpace($client, $org);

        $body = $client->request('POST', '/spaces/' . $space . '/members', [
            'json' => ['email' => 'outsider@example.com', 'role' => Space::ROLE_MEMBER],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();

        // A plain space member mirrors to a free org guest — collaborating on
        // one space shouldn't quietly add a seat to the invoice.
        $this->assertSame(Organization::ROLE_GUEST, $body['organizationRole']);

        $this->entityManager->clear();
        $reloaded = $this->reloadOrg($org);
        $this->assertSame(1, $reloaded->seatCount(), 'a guest must not consume a seat');
        $this->assertCount(2, $reloaded->getMemberships());
    }

    public function testSpaceAdminAutoJoinsTheOrgAsBillableMember(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $this->seedSubscription($org, 'sub_seats');
        $this->createUser('lead@example.com');

        $client = static::createClient();
        $client->loginUser($owner);
        $space = $this->makeOrgSpace($client, $org);

        $body = $client->request('POST', '/spaces/' . $space . '/members', [
            'json' => ['email' => 'lead@example.com', 'role' => Space::ROLE_ADMIN],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();

        $this->assertSame(Organization::ROLE_MEMBER, $body['organizationRole']);

        // ...and the new seat reached Stripe rather than being given away.
        $updates = $this->gateway()->quantityUpdates;
        $this->assertNotEmpty($updates, 'adding a billable member must push a seat quantity');
        $last = end($updates);
        $this->assertSame('sub_seats', $last['subscriptionId']);
        $this->assertSame(2, $last['quantity']);
    }

    public function testOrgAdminCanOverrideTheMirroredRole(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $this->createUser('contractor@example.com');

        $client = static::createClient();
        $client->loginUser($owner);
        $space = $this->makeOrgSpace($client, $org);

        $body = $client->request('POST', '/spaces/' . $space . '/members', [
            'json' => [
                'email' => 'contractor@example.com',
                'role' => Space::ROLE_MEMBER,
                'orgRole' => Organization::ROLE_MEMBER,
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();

        $this->assertSame(Organization::ROLE_MEMBER, $body['organizationRole']);
        $this->entityManager->clear();
        $this->assertSame(2, $this->reloadOrg($org)->seatCount());
    }

    public function testOwnerIsNotGrantableThroughTheSpaceEndpoint(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $this->createUser('sneaky@example.com');

        $client = static::createClient();
        $client->loginUser($owner);
        $space = $this->makeOrgSpace($client, $org);

        $client->request('POST', '/spaces/' . $space . '/members', [
            'json' => [
                'email' => 'sneaky@example.com',
                'role' => Space::ROLE_MEMBER,
                'orgRole' => Organization::ROLE_OWNER,
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testExistingOrgMemberIsNeverDemotedByASpaceAdd(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $admin = $this->createUser('admin@example.com');
        $org->addMember($admin, Organization::ROLE_ADMIN);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($owner);
        $space = $this->makeOrgSpace($client, $org);

        // Adding an existing org admin to a space as a plain member must not
        // mirror them down to guest — that would be an unattributable loss of
        // account-level access.
        $body = $client->request('POST', '/spaces/' . $space . '/members', [
            'json' => ['email' => 'admin@example.com', 'role' => Space::ROLE_MEMBER],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();

        $this->assertSame(Organization::ROLE_ADMIN, $body['organizationRole']);
    }

    public function testRemovingAMemberShrinksTheSeatQuantity(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $member = $this->createUser('member@example.com');
        $org->addMember($member, Organization::ROLE_MEMBER);
        $this->entityManager->flush();
        $this->seedSubscription($org, 'sub_shrink');

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('DELETE', '/organizations/' . $org->getId() . '/members/' . $member->getId());
        $this->assertResponseIsSuccessful();

        $updates = $this->gateway()->quantityUpdates;
        $this->assertNotEmpty($updates);
        $last = end($updates);
        $this->assertSame(1, $last['quantity'], 'removing a member must release its seat');
    }

    public function testDemotingToGuestCapsExistingSpaceAccess(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $member = $this->createUser('member@example.com');
        $org->addMember($member, Organization::ROLE_MEMBER);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($owner);
        $space = $this->makeOrgSpace($client, $org);

        $client->request('POST', '/spaces/' . $space . '/members', [
            'json' => ['email' => 'member@example.com', 'role' => Space::ROLE_ADMIN],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        // Demote at the org level; the space access they already hold has to
        // come down with it, or the account says guest while the space says
        // admin — and the space wins.
        $client->request('PATCH', '/organizations/' . $org->getId() . '/members/' . $member->getId(), [
            'json' => ['role' => Organization::ROLE_GUEST],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloadedSpace = $this->entityManager->getRepository(Space::class)->find($space);
        $this->assertInstanceOf(Space::class, $reloadedSpace);
        foreach ($reloadedSpace->getUserMemberships() as $membership) {
            if ('member@example.com' !== $membership->getUser()?->getEmail()) {
                continue;
            }
            $this->assertSame(Space::ROLE_MEMBER, $membership->getRole());
            $keys = [];
            foreach ($membership->getRoles() as $role) {
                $keys[] = $role->getBuiltinKey();
            }
            $this->assertSame([SpaceRole::BUILTIN_GUEST], $keys);
        }
    }

    public function testDemotionIsRefusedWhenItWouldStrandASpace(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $member = $this->createUser('member@example.com');
        $org->addMember($member, Organization::ROLE_MEMBER);
        $this->entityManager->flush();

        // A space whose only admin is the member we're about to demote.
        $client = static::createClient();
        $client->loginUser($member);
        $this->makeOrgSpace($client, $org);

        $client->loginUser($owner);
        $client->request('PATCH', '/organizations/' . $org->getId() . '/members/' . $member->getId(), [
            'json' => ['role' => Organization::ROLE_GUEST],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(409);
    }

    public function testNoStripeCallWithoutASubscription(): void
    {
        $owner = $this->createUser('owner@example.com');
        $org = $this->makeOrg($owner);
        $this->createUser('lead@example.com');

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', '/organizations/' . $org->getId() . '/members', [
            'json' => ['email' => 'lead@example.com', 'role' => Organization::ROLE_MEMBER],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        // A free org has nothing to re-size; the sync must stay silent rather
        // than erroring on the missing subscription.
        $this->assertSame([], $this->gateway()->quantityUpdates);
    }

    private function gateway(): InMemoryStripeGateway
    {
        $gateway = static::getContainer()->get(InMemoryStripeGateway::class);
        assert($gateway instanceof InMemoryStripeGateway);

        return $gateway;
    }

    private function reloadOrg(Organization $org): Organization
    {
        $reloaded = $this->entityManager->getRepository(Organization::class)->find($org->getId());
        $this->assertInstanceOf(Organization::class, $reloaded);

        return $reloaded;
    }

    private function seedSubscription(Organization $org, string $stripeId): void
    {
        $subscription = (new Subscription())
            ->setOrganization($org)
            ->setPlan('business')
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeCustomerId('cus_x')
            ->setStripeSubscriptionId($stripeId);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
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
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
