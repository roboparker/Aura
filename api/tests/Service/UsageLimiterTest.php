<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\UsageLimiter;
use App\Service\UsageRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Freemium gate read-side ({@see UsageLimiter}). The container-wired limiter
 * runs with enforcement OFF (the dark-launch default), so these tests build
 * the limiter by hand with enforcement ON and tight caps, against the real DB
 * + repository — exercising the actual counter read and entitlement query.
 */
class UsageLimiterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private SubscriptionRepository $subscriptions;
    private UsageRecorder $recorder;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $em = $container->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->connection = $em->getConnection();

        $subscriptions = $container->get(SubscriptionRepository::class);
        assert($subscriptions instanceof SubscriptionRepository);
        $this->subscriptions = $subscriptions;

        $recorder = $container->get(UsageRecorder::class);
        assert($recorder instanceof UsageRecorder);
        $this->recorder = $recorder;

        $this->em->createQuery('DELETE FROM App\Entity\Subscription')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->connection->executeStatement('DELETE FROM user_usage_counter');
    }

    public function testFreeUserUnderDailyLimitIsAllowed(): void
    {
        $user = $this->createUser('alice@example.com');
        $limiter = $this->limiter(mcpLimit: 3, memberLimit: 5);

        $this->recorder->recordMcpCall($user);
        $this->recorder->recordMcpCall($user);

        $this->assertTrue($limiter->isMcpCallAllowed($user));
        $this->assertSame(2, $limiter->mcpCallsToday($user));
        $this->assertSame(1, $limiter->remainingMcpCalls($user));
    }

    public function testFreeUserAtDailyLimitIsBlocked(): void
    {
        $user = $this->createUser('alice@example.com');
        $limiter = $this->limiter(mcpLimit: 3, memberLimit: 5);

        $this->recorder->recordMcpCall($user);
        $this->recorder->recordMcpCall($user);
        $this->recorder->recordMcpCall($user);

        $this->assertFalse($limiter->isMcpCallAllowed($user));
        $this->assertSame(0, $limiter->remainingMcpCalls($user));
    }

    public function testEntitledUserHasUnlimitedThroughput(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Team');
        $this->createSubscription($space, Subscription::STATUS_ACTIVE);

        $limiter = $this->limiter(mcpLimit: 1, memberLimit: 5);

        // Well over the cap, but the active subscription lifts it.
        $this->recorder->recordMcpCall($alice);
        $this->recorder->recordMcpCall($alice);

        $this->assertTrue($limiter->isUserEntitled($alice));
        $this->assertTrue($limiter->isMcpCallAllowed($alice));
        $this->assertNull($limiter->remainingMcpCalls($alice));
    }

    public function testCanceledSubscriptionDoesNotEntitle(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Team');
        $this->createSubscription($space, Subscription::STATUS_CANCELED);

        $limiter = $this->limiter(mcpLimit: 1, memberLimit: 5);

        $this->assertFalse($limiter->isUserEntitled($alice));
    }

    public function testEnforcementDisabledAlwaysAllows(): void
    {
        $user = $this->createUser('alice@example.com');
        $limiter = $this->limiter(mcpLimit: 1, memberLimit: 1, enforcement: false);

        $this->recorder->recordMcpCall($user);
        $this->recorder->recordMcpCall($user);

        $this->assertTrue($limiter->isMcpCallAllowed($user));
        $this->assertNull($limiter->remainingMcpCalls($user));
    }

    public function testFreeSpaceMemberCap(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Team'); // alice is one admin member
        $this->addMember($space, $bob);              // now two members

        $limiter = $this->limiter(mcpLimit: 100, memberLimit: 2);

        // At the cap (2 members, limit 2) → no more on free.
        $this->assertFalse($limiter->canAddMembersToSpace($space));

        // A subscription lifts the cap.
        $this->createSubscription($space, Subscription::STATUS_ACTIVE);
        $this->em->clear();
        $reloaded = $this->em->getRepository(Space::class)->findOneBy(['name' => 'Team']);
        $this->assertInstanceOf(Space::class, $reloaded);
        $this->assertTrue($limiter->canAddMembersToSpace($reloaded));
    }

    public function testPersonalSpaceIsNeverCapped(): void
    {
        $alice = $this->createUser('alice@example.com');
        $personal = $this->createSpace($alice, 'Private', personal: true);

        $limiter = $this->limiter(mcpLimit: 100, memberLimit: 1);

        $this->assertTrue($limiter->canAddMembersToSpace($personal));
    }

    private function limiter(int $mcpLimit, int $memberLimit, bool $enforcement = true): UsageLimiter
    {
        return new UsageLimiter(
            $this->connection,
            $this->subscriptions,
            freeMcpDailyLimit: $mcpLimit,
            freeSpaceMemberLimit: $memberLimit,
            enforcementEnabled: $enforcement,
        );
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createSpace(User $owner, string $name, bool $personal = false): Space
    {
        $space = new Space();
        $space->setName($name);
        $space->setCreatedBy($owner);
        if ($personal) {
            $space->setIsPersonal(true);
            $space->setVisibility(Space::VISIBILITY_PRIVATE);
        }
        $this->em->persist($space);

        $membership = (new SpaceMembership())->setUser($owner)->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($membership);
        $this->em->persist($membership);
        $this->em->flush();

        return $space;
    }

    private function addMember(Space $space, User $user): void
    {
        $membership = (new SpaceMembership())->setUser($user)->setRole(Space::ROLE_MEMBER);
        $space->addUserMembership($membership);
        $this->em->persist($membership);
        $this->em->flush();
    }

    private function createSubscription(Space $space, string $status): Subscription
    {
        $subscription = (new Subscription())
            ->setSpace($space)
            ->setStatus($status)
            ->setStripeSubscriptionId('sub_' . bin2hex(random_bytes(6)))
            ->setStripeCustomerId('cus_' . bin2hex(random_bytes(6)));
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}
