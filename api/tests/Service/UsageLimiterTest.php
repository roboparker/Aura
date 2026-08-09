<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Billing\PlanCatalog;
use App\Billing\PlanGate;
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

        // phpstan-symfony already infers the concrete type from the
        // class-string arg, so no instanceof assert is needed here.
        $this->subscriptions = $container->get(SubscriptionRepository::class);
        $this->recorder = $container->get(UsageRecorder::class);

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

    public function testAdminIsUncappedButStillCounted(): void
    {
        $admin = $this->createUser('admin@example.com');
        $admin->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $this->em->flush();

        $limiter = $this->limiter(mcpLimit: 1, memberLimit: 5);

        // Two calls, well past the cap of 1 — but admins aren't capped.
        $this->recorder->recordMcpCall($admin);
        $this->recorder->recordMcpCall($admin);

        $this->assertTrue($limiter->isAdmin($admin));
        $this->assertFalse($limiter->isUserEntitled($admin));
        $this->assertTrue($limiter->isMcpCallAllowed($admin));
        $this->assertNull($limiter->remainingMcpCalls($admin));

        // Tracking is unaffected — their usage is still recorded.
        $this->assertSame(2, $limiter->mcpCallsToday($admin));
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

    public function testFreeSeatCapIsCountedPerOrganization(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Team');
        $org = $space->getOrganization();
        $this->assertNotNull($org);

        // Seats are org members, not space members (#billing Phase 2): alice
        // owns the account, bob takes the second seat.
        $org->addMember($bob, Organization::ROLE_MEMBER);
        $this->em->flush();

        $limiter = $this->limiter(mcpLimit: 100, memberLimit: 2);

        // At the cap (2 seats, limit 2) → no more on free.
        $this->assertFalse($limiter->canAddMembersToSpace($space));
        $this->assertFalse($limiter->canAddMembersToOrganization($org));

        // A subscription lifts it entirely.
        $this->createSubscription($space, Subscription::STATUS_ACTIVE);
        $this->em->clear();
        $reloaded = $this->em->getRepository(Space::class)->findOneBy(['name' => 'Team']);
        $this->assertInstanceOf(Space::class, $reloaded);
        $this->assertTrue($limiter->canAddMembersToSpace($reloaded));
    }

    public function testGuestsDoNotConsumeASeat(): void
    {
        $alice = $this->createUser('alice@example.com');
        $guest = $this->createUser('guest@example.com');
        $space = $this->createSpace($alice, 'Team');
        $org = $space->getOrganization();
        $this->assertNotNull($org);

        $org->addMember($guest, Organization::ROLE_GUEST);
        $this->em->flush();

        // Two members, but one is free — that is the whole point of the guest
        // role, and it is what makes "N free seats" a truthful description.
        $limiter = $this->limiter(mcpLimit: 100, memberLimit: 2);
        $this->assertTrue($limiter->canAddMembersToOrganization($org));
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
        // The catalog's Free-tier caps are the test's caps; a subscription
        // (default legacy 'team' → Business) lifts both to unlimited.
        $planGate = new PlanGate($this->subscriptions, new PlanCatalog($memberLimit, $mcpLimit));

        return new UsageLimiter(
            $this->connection,
            $this->subscriptions,
            $planGate,
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
        // Subscriptions hang off the organization that owns the space; the
        // default listener attached one when the space was persisted.
        $subscription = (new Subscription())
            ->setOrganization($space->getOrganization())
            ->setStatus($status)
            ->setStripeSubscriptionId('sub_' . bin2hex(random_bytes(6)))
            ->setStripeCustomerId('cus_' . bin2hex(random_bytes(6)));
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}
