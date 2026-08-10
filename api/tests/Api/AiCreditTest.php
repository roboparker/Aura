<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Ai\AiUnavailableException;
use App\Ai\ChatMessage;
use App\Ai\ChatProviderException;
use App\Ai\ChatResponse;
use App\Billing\Plan;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\AgentChatService;
use App\Service\AgentProvisioner;
use App\Service\AiCreditMeter;
use App\Tests\Ai\InMemoryChatProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * AI agents, step 2 (#827): the provider seam and the credit meter.
 *
 * The behaviour that matters here is the *ordering* — reserve before the call,
 * reconcile after — because that is what stops a mid-flight failure being
 * either free (unbounded spend) or double-charged.
 */
class AiCreditTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        $this->connection = $em->getConnection();

        $this->connection->executeStatement('DELETE FROM ai_credit_ledger');
        foreach (
            ['ApiToken', 'Subscription', 'Space', 'OrganizationMembership', 'Organization', 'User'] as $entity
        ) {
            $this->entityManager->createQuery("DELETE FROM App\\Entity\\$entity")->execute();
        }

        $this->provider()->reset();
    }

    // --- The metered call --------------------------------------------------

    public function testASuccessfulCallSettlesToTheReportedUsage(): void
    {
        [$space, $agent, $organization] = $this->businessScenario();
        $this->provider()->setUsage(promptTokens: 300, completionTokens: 200);

        $response = $this->chat()->reply($agent, $space, [ChatMessage::user('Hello?')]);

        $this->assertSame('Hello from the test model.', $response->content);
        $this->assertSame(500, $response->totalTokens());

        // The reservation was the worst case; the settled charge is what the
        // call actually cost, not what was held for it.
        $balance = $this->meter()->balance($organization);
        $this->assertSame(500, $balance->settledTokens);
        $this->assertSame(0, $balance->pendingTokens);
        $this->assertSame(500, $balance->usedTokens());
        $this->assertCount(1, $this->ledgerRows());
    }

    public function testTheReservationIsTakenBeforeTheCall(): void
    {
        // The whole ordering argument in one assertion: while the provider is
        // mid-call the credits are already committed, so a second concurrent
        // request cannot also spend them.
        [$space, $agent, $organization] = $this->businessScenario();
        $observed = null;

        $this->provider()->onComplete(function () use ($organization, &$observed): void {
            $observed = $this->meter()->balance($organization)->usedTokens();
        });

        $this->chat()->reply($agent, $space, [ChatMessage::user('Hello?')], maxOutputTokens: 400);

        $this->assertIsInt($observed);
        $this->assertGreaterThanOrEqual(400, $observed);
    }

    public function testAFailedCallIsNotCharged(): void
    {
        [$space, $agent, $organization] = $this->businessScenario();
        $this->provider()->failWith(ChatProviderException::rateLimited('openai'));

        try {
            $this->chat()->reply($agent, $space, [ChatMessage::user('Hello?')]);
            $this->fail('The provider failure should have propagated.');
        } catch (ChatProviderException $e) {
            $this->assertTrue($e->retryable);
        }

        // Released, not left pending: the next attempt starts from a clean
        // balance rather than one dented by a call that produced nothing.
        $balance = $this->meter()->balance($organization);
        $this->assertSame(0, $balance->usedTokens());
        $this->assertCount(0, $this->ledgerRows());
    }

    public function testSpendIsAttributedToTheAgentAndThePeriod(): void
    {
        [$space, $agent, $organization] = $this->businessScenario();
        $this->chat()->reply($agent, $space, [ChatMessage::user('Hello?')]);

        $rows = $this->ledgerRows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame((string) $agent->getId(), $row['agent_id']);
        $this->assertSame((string) $organization->getId(), $row['organization_id']);
        $this->assertSame($this->meter()->currentPeriod(), $row['period']);
        $this->assertSame('settled', $row['state']);
    }

    // --- Enforcement -------------------------------------------------------

    public function testAPlanWithoutAiIsRefusedBeforeAnythingIsSpent(): void
    {
        // Free is the default, and the refusal must name the plan rather than
        // report "out of credits" — being told you've exhausted an allowance
        // you never had is a confusing way to be sold an upgrade.
        [$space, $agent] = $this->scenario(Plan::Free);

        try {
            $this->chat()->reply($agent, $space, [ChatMessage::user('Hello?')]);
            $this->fail('A Free plan must not reach a model.');
        } catch (AiUnavailableException $e) {
            $this->assertSame(AiUnavailableException::REASON_PLAN, $e->reason);
        }

        $this->assertCount(0, $this->provider()->requests);
        $this->assertCount(0, $this->ledgerRows());
    }

    public function testAnExhaustedAllowanceRefusesTheNextCall(): void
    {
        [$space, $agent, $organization] = $this->businessScenario();
        $allowance = $this->meter()->allowanceTokens($organization);
        $this->assertIsInt($allowance);

        // Spend the month in one settled charge.
        $this->spend($organization, $agent, $allowance);

        try {
            $this->chat()->reply($agent, $space, [ChatMessage::user('One more?')]);
            $this->fail('An exhausted allowance must refuse.');
        } catch (AiUnavailableException $e) {
            $this->assertSame(AiUnavailableException::REASON_CREDITS, $e->reason);
        }
        $this->assertCount(0, $this->provider()->requests);
    }

    public function testAnUnconfiguredInstanceReportsItselfRatherThanFailingMidCall(): void
    {
        [$space, $agent] = $this->businessScenario();
        $this->provider()->setConfigured(false);

        $reason = $this->chat()->unavailableReason($space);
        $this->assertInstanceOf(AiUnavailableException::class, $reason);
        $this->assertSame(AiUnavailableException::REASON_PROVIDER, $reason->reason);

        try {
            $this->chat()->reply($agent, $space, [ChatMessage::user('Hello?')]);
            $this->fail('An unconfigured instance must not attempt a call.');
        } catch (AiUnavailableException $e) {
            $this->assertSame(AiUnavailableException::REASON_PROVIDER, $e->reason);
        }
    }

    public function testAnEntitledSpaceHasNoUnavailableReason(): void
    {
        [$space] = $this->businessScenario();
        $this->assertNull($this->chat()->unavailableReason($space));
    }

    // --- The meter itself --------------------------------------------------

    public function testAReservationHoldsCreditsUntilItLapses(): void
    {
        [, $agent, $organization] = $this->businessScenario();
        $now = new \DateTimeImmutable('2026-08-10 12:00:00', new \DateTimeZone('UTC'));

        $this->meter()->reserve($organization, $agent, 1000, 'openai', 'test-model', $now);

        // Held while live...
        $this->assertSame(1000, $this->meter()->balance($organization, $now)->usedTokens());
        // ...and self-healing once the TTL passes, so a crashed process can't
        // strand an account's allowance with no cron to rescue it.
        $later = $now->modify('+1 hour');
        $this->assertSame(0, $this->meter()->balance($organization, $later)->usedTokens());
    }

    public function testPurgingLapsedReservationsIsHousekeepingOnly(): void
    {
        [, $agent, $organization] = $this->businessScenario();
        $now = new \DateTimeImmutable('2026-08-10 12:00:00', new \DateTimeZone('UTC'));
        $this->meter()->reserve($organization, $agent, 1000, 'openai', 'test-model', $now);

        $purged = $this->meter()->purgeExpiredReservations($now->modify('+1 hour'));

        $this->assertSame(1, $purged);
        $this->assertCount(0, $this->ledgerRows());
    }

    public function testReleasingAndSettlingAreEachIdempotent(): void
    {
        [, $agent, $organization] = $this->businessScenario();
        $reservation = $this->meter()->reserve($organization, $agent, 1000, 'openai', 'test-model');

        $this->meter()->release($reservation);
        $this->meter()->release($reservation);
        $this->assertCount(0, $this->ledgerRows());

        // A settle against an already-released row is a no-op rather than a
        // resurrection — the guard is `state = pending`, and the row is gone.
        $this->meter()->settle($reservation, new ChatResponse('hi', 10, 5, 'test-model'));
        $this->assertCount(0, $this->ledgerRows());
    }

    public function testAllowanceFollowsThePlan(): void
    {
        $perCredit = $this->meter()->tokensPerCredit();

        [, , $free] = $this->scenario(Plan::Free);
        $this->assertSame(0, $this->meter()->allowanceTokens($free));

        [, , $business] = $this->scenario(Plan::Business);
        $this->assertSame(2000 * $perCredit, $this->meter()->allowanceTokens($business));

        [, , $enterprise] = $this->scenario(Plan::Enterprise);
        $this->assertSame(10000 * $perCredit, $this->meter()->allowanceTokens($enterprise));
    }

    public function testCreditsAreRoundedUpForDisplay(): void
    {
        [, $agent, $organization] = $this->businessScenario();
        // A single token spent is a credit started, not zero usage — rounding
        // down would let a long tail of small calls read as "nothing used".
        $this->spend($organization, $agent, 1);

        $this->assertSame(1, $this->meter()->balance($organization)->usedCredits());
    }

    // --- The read endpoint -------------------------------------------------

    public function testAMemberReadsTheBalanceAndAStrangerCannot(): void
    {
        [$space, , $organization] = $this->businessScenario();
        $member = $this->createUser('member@example.com');
        $this->joinSpace($space, $member);
        $stranger = $this->createUser('stranger@example.com');

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('GET', '/spaces/' . $space->getId() . '/ai-credits');
        $this->assertResponseIsSuccessful();

        $body = $this->body($client);
        $this->assertSame(2000, $this->intField($body, 'allowanceCredits'));
        $this->assertSame(0, $this->intField($body, 'usedCredits'));
        $this->assertFalse($this->boolField($body, 'unlimited'));
        // The pooling account is named, so two spaces showing the same numbers
        // reads as shared rather than broken.
        $this->assertSame(
            (string) $organization->getId(),
            $this->stringField($this->arrayField($body, 'organization'), 'id'),
        );
        $this->assertNull($body['unavailableReason'] ?? 'missing');

        $client->loginUser($stranger);
        $client->request('GET', '/spaces/' . $space->getId() . '/ai-credits');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testTheBalanceEndpointExplainsWhyAgentsCannotAnswer(): void
    {
        [$space] = $this->scenario(Plan::Free);
        $owner = $this->createUser('owner2@example.com');
        $this->joinSpace($space, $owner);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('GET', '/spaces/' . $space->getId() . '/ai-credits');
        $this->assertResponseIsSuccessful();

        $body = $this->body($client);
        $this->assertSame(AiUnavailableException::REASON_PLAN, $this->stringField($body, 'unavailableReason'));
        $this->assertNotSame('', $this->stringField($body, 'unavailableMessage'));
    }

    public function testTheBalanceEndpointRefusesAnonymousAndUnknownSpaces(): void
    {
        $anonymous = static::createClient();
        $anonymous->request('GET', '/spaces/' . Uuid::v4() . '/ai-credits');
        $this->assertResponseStatusCodeSame(401);

        $user = $this->createUser('someone@example.com');
        $client = static::createClient();
        $client->loginUser($user);
        $client->request('GET', '/spaces/not-a-uuid/ai-credits');
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/spaces/' . Uuid::v4() . '/ai-credits');
        $this->assertResponseStatusCodeSame(404);
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * @return array<int|string, mixed>
     */
    private function body(Client $client): array
    {
        $response = $client->getResponse();
        self::assertNotNull($response);

        return $response->toArray(false);
    }

    private function provider(): InMemoryChatProvider
    {
        $provider = static::getContainer()->get(InMemoryChatProvider::class);
        assert($provider instanceof InMemoryChatProvider);

        return $provider;
    }

    private function chat(): AgentChatService
    {
        $service = static::getContainer()->get(AgentChatService::class);
        assert($service instanceof AgentChatService);

        return $service;
    }

    private function meter(): AiCreditMeter
    {
        $meter = static::getContainer()->get(AiCreditMeter::class);
        assert($meter instanceof AiCreditMeter);

        return $meter;
    }

    /** Book a settled charge directly, to put an account at a known balance. */
    private function spend(Organization $organization, User $agent, int $tokens): void
    {
        $reservation = $this->meter()->reserve($organization, $agent, $tokens, 'openai', 'test-model');
        $this->meter()->settle($reservation, new ChatResponse('spent', $tokens, 0, 'test-model'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ledgerRows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM ai_credit_ledger ORDER BY created_at');

        return $rows;
    }

    /**
     * A Business-plan space with an agent in it — the entitled happy path.
     *
     * @return array{0: Space, 1: User, 2: Organization}
     */
    private function businessScenario(): array
    {
        return $this->scenario(Plan::Business);
    }

    /**
     * @return array{0: Space, 1: User, 2: Organization}
     */
    private function scenario(Plan $plan): array
    {
        $owner = $this->createUser('owner-' . bin2hex(random_bytes(4)) . '@example.com');
        $organization = $this->createOrganization($owner);
        if (Plan::Free !== $plan) {
            $this->subscribe($organization, $plan);
        }
        $space = $this->createSpace($owner, $organization);

        $provisioner = static::getContainer()->get(AgentProvisioner::class);
        assert($provisioner instanceof AgentProvisioner);
        $result = $provisioner->provision($space, 'Helper', []);
        $this->entityManager->flush();

        return [$space, $result['agent'], $organization];
    }

    private function subscribe(Organization $organization, Plan $plan): void
    {
        $subscription = (new Subscription())
            ->setOrganization($organization)
            ->setPlan($plan->value)
            ->setStatus(Subscription::STATUS_ACTIVE);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
    }

    private function createOrganization(User $owner): Organization
    {
        $organization = (new Organization())
            ->setName('Acme')
            ->setSlug('o-acme-' . bin2hex(random_bytes(4)))
            ->setCreatedBy($owner);
        $organization->addMember($owner, Organization::ROLE_OWNER);
        $this->entityManager->persist($organization);
        foreach ($organization->getMemberships() as $membership) {
            $this->entityManager->persist($membership);
        }
        $this->entityManager->flush();

        return $organization;
    }

    private function createSpace(User $admin, Organization $organization): Space
    {
        $space = (new Space())
            ->setName('Team')
            ->setCreatedBy($admin)
            ->setOrganization($organization);
        $this->entityManager->persist($space);
        $membership = (new SpaceMembership())->setUser($admin)->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($membership);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        return $space;
    }

    private function joinSpace(Space $space, User $user): void
    {
        $membership = (new SpaceMembership())->setUser($user)->setRole(Space::ROLE_MEMBER);
        $space->addUserMembership($membership);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
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
