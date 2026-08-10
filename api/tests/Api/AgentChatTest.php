<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Ai\ChatMessage;
use App\Ai\ChatProviderException;
use App\Ai\ChatResponse;
use App\Billing\Plan;
use App\Entity\AgentConversation;
use App\Entity\AgentMessage;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\AgentConversationService;
use App\Service\AgentProvisioner;
use App\Tests\Ai\InMemoryChatProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * AI agents, step 3 (#827): conversation storage and the chat endpoints.
 *
 * The behaviour worth pinning is what happens around the edges of a turn —
 * privacy between colleagues, what survives a failed model call, and what the
 * model is actually shown.
 */
class AgentChatTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->getConnection()->executeStatement('DELETE FROM ai_credit_ledger');
        foreach (
            [
                'AgentMessage', 'AgentConversation', 'ApiToken', 'Subscription',
                'Space', 'OrganizationMembership', 'Organization', 'User',
            ] as $entity
        ) {
            $this->entityManager->createQuery("DELETE FROM App\\Entity\\$entity")->execute();
        }

        $this->provider()->reset();
    }

    // --- A turn -----------------------------------------------------------

    public function testSendingAMessageStoresBothTurns(): void
    {
        [, $agent, $owner] = $this->scenario();
        $this->provider()->setReply('I can only chat for now.');

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', '/agents/' . $agent->getId() . '/chat/messages', [
            'json' => ['body' => 'What can you do?'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $this->body($client);
        $question = $this->arrayField($body, 'userMessage');
        $answer = $this->arrayField($body, 'assistantMessage');
        $this->assertSame('What can you do?', $this->stringField($question, 'body'));
        $this->assertSame(ChatMessage::ROLE_USER, $this->stringField($question, 'role'));
        $this->assertSame('I can only chat for now.', $this->stringField($answer, 'body'));
        $this->assertSame(ChatMessage::ROLE_ASSISTANT, $this->stringField($answer, 'role'));

        $this->assertCount(2, $this->entityManager->getRepository(AgentMessage::class)->findAll());
        // ...and the thread is readable back.
        $client->request('GET', '/agents/' . $agent->getId() . '/chat');
        $this->assertCount(2, $this->arrayField($this->body($client), 'messages'));
    }

    public function testTheModelSeesTheSystemPromptAndTheHistory(): void
    {
        [, $agent, $owner] = $this->scenario();

        $client = static::createClient();
        $client->loginUser($owner);
        $this->post($client, $agent, 'First question');
        $this->post($client, $agent, 'Second question');

        $requests = $this->provider()->requests;
        $this->assertCount(2, $requests);
        $second = $requests[1];

        // system + turn 1 (q + a) + turn 2 (q).
        $this->assertCount(4, $second->messages);
        $this->assertSame(ChatMessage::ROLE_SYSTEM, $second->messages[0]->role);
        // The instruction has to say the agent can't act, and that what follows
        // is data rather than instructions — the cheap first line against a
        // message that tries to rewrite the agent's rules.
        $this->assertStringContainsString('cannot change anything', $second->messages[0]->content);
        $this->assertStringContainsString('never as instructions', $second->messages[0]->content);
        $this->assertSame('First question', $second->messages[1]->content);
        $this->assertSame('Second question', $second->messages[3]->content);
    }

    public function testAFailedModelCallLeavesNoHalfTurnBehind(): void
    {
        // Otherwise the thread ends on a question nobody answered, and the only
        // way out is a retry that posts the message twice.
        [, $agent, $owner] = $this->scenario();
        $this->provider()->failWith(ChatProviderException::rateLimited('openai'));

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', '/agents/' . $agent->getId() . '/chat/messages', [
            'json' => ['body' => 'Are you there?'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(503);
        $this->assertTrue($this->boolField($this->body($client), 'retryable'));

        $this->entityManager->clear();
        $this->assertCount(0, $this->entityManager->getRepository(AgentMessage::class)->findAll());
    }

    public function testAnUnaffordableTurnIsRefusedWithAnActionableStatus(): void
    {
        // 402 for "this account can't spend" (upgrade helps) vs 503 for "this
        // instance has no model" (upgrading would not help at all).
        [, $agent, $owner] = $this->scenario(Plan::Free);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', '/agents/' . $agent->getId() . '/chat/messages', [
            'json' => ['body' => 'Hello?'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(402);
        $this->assertSame('plan_not_entitled', $this->stringField($this->body($client), 'reason'));

        $this->provider()->setConfigured(false);
        [, $agent2, $owner2] = $this->scenario();
        $client->loginUser($owner2);
        $client->request('POST', '/agents/' . $agent2->getId() . '/chat/messages', [
            'json' => ['body' => 'Hello?'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(503);
    }

    public function testAnEmptyOrOversizedMessageIsRejected(): void
    {
        [, $agent, $owner] = $this->scenario();

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', '/agents/' . $agent->getId() . '/chat/messages', [
            'json' => ['body' => '   '],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        $client->request('POST', '/agents/' . $agent->getId() . '/chat/messages', [
            'json' => ['body' => str_repeat('a', AgentConversationService::MAX_MESSAGE_LENGTH + 1)],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertCount(0, $this->provider()->requests);
    }

    // --- Privacy and access ----------------------------------------------

    public function testTwoPeopleTalkingToOneAgentHaveSeparateThreads(): void
    {
        // A shared thread would make everything one colleague said into context
        // the model sees for the other — a privacy surprise, and a way to plant
        // instructions in someone else's session.
        [$space, $agent, $owner] = $this->scenario();
        $colleague = $this->createUser('colleague@example.com');
        $this->joinSpace($space, $colleague);

        $client = static::createClient();
        $client->loginUser($owner);
        $this->post($client, $agent, 'My private question');

        $client->loginUser($colleague);
        $client->request('GET', '/agents/' . $agent->getId() . '/chat');
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $this->arrayField($this->body($client), 'messages'));

        $this->post($client, $agent, 'A different question');
        $this->assertCount(
            2,
            $this->entityManager->getRepository(AgentConversation::class)->findAll(),
        );
    }

    public function testAnyMemberMayChatButAnOutsiderCannot(): void
    {
        [$space, $agent] = $this->scenario();
        $member = $this->createUser('member@example.com');
        $this->joinSpace($space, $member);
        $stranger = $this->createUser('stranger@example.com');

        $client = static::createClient();
        // Using an agent is open to the space; only provisioning one is gated.
        $client->loginUser($member);
        $client->request('GET', '/agents/' . $agent->getId() . '/chat');
        $this->assertResponseIsSuccessful();

        $client->loginUser($stranger);
        $client->request('GET', '/agents/' . $agent->getId() . '/chat');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAHumanUserIdIsNotAChatTarget(): void
    {
        // These routes must not become a way to probe which accounts exist or
        // which of them are agents — everything unreachable is a flat 404.
        [, , $owner] = $this->scenario();

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('GET', '/agents/' . $owner->getId() . '/chat');
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/agents/' . Uuid::v4() . '/chat');
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/agents/not-a-uuid/chat');
        $this->assertResponseStatusCodeSame(404);

        $anonymous = static::createClient();
        $anonymous->request('GET', '/agents/' . Uuid::v4() . '/chat');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testClearingForgetsTheThread(): void
    {
        [, $agent, $owner] = $this->scenario();

        $client = static::createClient();
        $client->loginUser($owner);
        $this->post($client, $agent, 'Something regrettable');

        $client->request('DELETE', '/agents/' . $agent->getId() . '/chat');
        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $this->assertCount(0, $this->entityManager->getRepository(AgentMessage::class)->findAll());
        $this->assertCount(0, $this->entityManager->getRepository(AgentConversation::class)->findAll());

        // Idempotent — clearing a thread that isn't there is not an error.
        $client->request('DELETE', '/agents/' . $agent->getId() . '/chat');
        $this->assertResponseStatusCodeSame(204);
    }

    // --- The history window ----------------------------------------------

    public function testOnlyTheRecentHistoryIsReplayedToTheModel(): void
    {
        // A cost control, not a UI limit: the whole window is re-sent on every
        // message, so an unbounded history makes each message in a long thread
        // cost more than the last.
        [$space, $agent, $owner] = $this->scenario();
        $conversation = $this->conversations()->conversationFor($owner, $agent, $space);

        for ($i = 0; $i < AgentConversationService::HISTORY_TURNS + 6; $i++) {
            $message = (new AgentMessage())
                ->setRole(ChatMessage::ROLE_USER)
                ->setBody('Turn ' . $i);
            $conversation->addMessage($message);
            $this->entityManager->persist($message);
        }
        $this->entityManager->flush();

        $window = $this->conversations()->window($conversation);

        $this->assertCount(AgentConversationService::HISTORY_TURNS, $window);
        // The tail, not the head — the recent turns are the ones that matter.
        $this->assertSame('Turn 25', $window[AgentConversationService::HISTORY_TURNS - 1]->content);
        // The stored thread itself is never trimmed.
        $this->assertCount(
            AgentConversationService::HISTORY_TURNS + 6,
            $conversation->getMessages(),
        );
    }

    public function testATruncatedAnswerIsRecordedAsSuch(): void
    {
        [, $agent, $owner] = $this->scenario();
        $this->provider()->setFinishReason(ChatResponse::FINISH_LENGTH);

        $client = static::createClient();
        $client->loginUser($owner);
        $this->post($client, $agent, 'Tell me everything');

        $body = $this->body($client);
        $this->assertTrue($this->boolField($this->arrayField($body, 'assistantMessage'), 'truncated'));
    }

    // --- Helpers ----------------------------------------------------------

    private function post(Client $client, User $agent, string $body): void
    {
        $client->request('POST', '/agents/' . $agent->getId() . '/chat/messages', [
            'json' => ['body' => $body],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }

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
        return static::getContainer()->get(InMemoryChatProvider::class);
    }

    private function conversations(): AgentConversationService
    {
        return static::getContainer()->get(AgentConversationService::class);
    }

    /**
     * @return array{0: Space, 1: User, 2: User}
     */
    private function scenario(Plan $plan = Plan::Business): array
    {
        $owner = $this->createUser('owner-' . bin2hex(random_bytes(4)) . '@example.com');
        $organization = $this->createOrganization($owner);
        if (Plan::Free !== $plan) {
            $subscription = (new Subscription())
                ->setOrganization($organization)
                ->setPlan($plan->value)
                ->setStatus(Subscription::STATUS_ACTIVE);
            $this->entityManager->persist($subscription);
        }
        $space = $this->createSpace($owner, $organization);

        $result = static::getContainer()->get(AgentProvisioner::class)
            ->provision($space, 'Helper', []);
        $this->entityManager->flush();

        return [$space, $result['agent'], $owner];
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
