<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Feedback;
use App\Entity\User;
use App\Service\AccountDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class FeedbackTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->createQuery('DELETE FROM App\Entity\FeedbackVote')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Feedback')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAnonymousCannotListOrCreate(): void
    {
        $client = static::createClient();

        $client->request('GET', '/feedback');
        $this->assertResponseStatusCodeSame(401);

        $client->request('POST', '/feedback', [
            'json' => ['title' => 'X', 'description' => 'Y', 'type' => 'bug'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateStampsOwnerStatusAndSequentialTicketNumber(): void
    {
        $user = $this->makeUser('user@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $first = $this->createTicket($client, 'Dark mode please', 'feature');
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('open', $this->stringField($first, 'status'));
        $this->assertSame('feature', $this->stringField($first, 'type'));
        $owner = $this->arrayField($first, 'owner');
        $this->assertSame('user@example.com', $this->stringField($owner, 'email'));
        // Aggregates present on create-time read.
        $this->assertSame(0, $this->intField($first, 'score'));
        $this->assertSame(0, $this->intField($first, 'myVote'));

        $second = $this->createTicket($client, 'Crash on save', 'bug');
        $this->assertResponseStatusCodeSame(201);

        // Ticket numbers are monotonic (sequence-backed), not necessarily 1/2
        // since the sequence persists across the suite.
        $this->assertGreaterThan(
            $this->intField($first, 'ticketNumber'),
            $this->intField($second, 'ticketNumber'),
        );
    }

    public function testClientCannotForgeTicketNumberOrOwner(): void
    {
        $a = $this->makeUser('a@example.com');
        $b = $this->makeUser('b@example.com');
        $client = static::createClient();
        $client->loginUser($a);

        $client->request('POST', '/feedback', [
            'json' => [
                'title' => 'Forged',
                'description' => 'Trying to forge fields',
                'type' => 'bug',
                'ticketNumber' => 99999,
                'owner' => '/users/' . $b->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $this->body($client);
        $this->assertNotSame(99999, $this->intField($body, 'ticketNumber'));
        $owner = $this->arrayField($body, 'owner');
        $this->assertSame('a@example.com', $this->stringField($owner, 'email'));
    }

    public function testValidationRejectsBadTypeAndBlankTitle(): void
    {
        $user = $this->makeUser('user@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/feedback', [
            'json' => ['title' => '', 'description' => 'desc', 'type' => 'bug'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        $client->request('POST', '/feedback', [
            'json' => ['title' => 'T', 'description' => 'D', 'type' => 'wat'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testTypeAndStatusFiltering(): void
    {
        $user = $this->makeUser('user@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $this->createTicket($client, 'Bug one', 'bug');
        $this->createTicket($client, 'Feature one', 'feature');

        $client->request('GET', '/feedback?type=bug');
        $this->assertResponseIsSuccessful();
        $members = $this->members($client);
        $this->assertCount(1, $members);

        $client->request('GET', '/feedback?status=shipped');
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $this->members($client));
    }

    public function testOwnerCanEditTitleButNotStatus(): void
    {
        $user = $this->makeUser('user@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $ticket = $this->createTicket($client, 'Editable', 'bug');
        $iri = $this->stringField($ticket, '@id');

        // Owner edits the title — allowed.
        $client->request('PATCH', $iri, [
            'json' => ['title' => 'Edited title'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Edited title', $this->stringField($this->body($client), 'title'));

        // Owner tries to move the status — forbidden (admin-only field).
        $client->request('PATCH', $iri, [
            'json' => ['status' => 'shipped'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanChangeStatus(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);

        $client = static::createClient();
        $client->loginUser($owner);
        $ticket = $this->createTicket($client, 'Status move', 'feature');
        $iri = $this->stringField($ticket, '@id');

        $client->loginUser($admin);
        $client->request('PATCH', $iri, [
            'json' => ['status' => 'planned'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('planned', $this->stringField($this->body($client), 'status'));
    }

    public function testNonOwnerCannotEditOrDelete(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $other = $this->makeUser('other@example.com');

        $client = static::createClient();
        $client->loginUser($owner);
        $ticket = $this->createTicket($client, 'Mine', 'bug');
        $iri = $this->stringField($ticket, '@id');

        $client->loginUser($other);
        $client->request('PATCH', $iri, [
            'json' => ['title' => 'Hijack'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        $client->request('DELETE', $iri);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanDelete(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $client = static::createClient();
        $client->loginUser($owner);
        $ticket = $this->createTicket($client, 'Delete me', 'bug');
        $iri = $this->stringField($ticket, '@id');

        $client->request('DELETE', $iri);
        $this->assertResponseStatusCodeSame(204);
    }

    public function testTicketSurvivesOwnerAccountDeletion(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $client = static::createClient();
        $client->loginUser($owner);
        $ticket = $this->createTicket($client, 'Outlives me', 'feature');
        $ticketId = $this->stringField($ticket, 'id');

        $service = static::getContainer()->get(AccountDeletionService::class);
        $service->deleteAccount($owner);

        $this->em->clear();
        $survivor = $this->em->getRepository(Feedback::class)->find($ticketId);
        $this->assertNotNull($survivor);
        $this->assertSame(
            AccountDeletionService::SENTINEL_EMAIL,
            $survivor->getOwner()?->getEmail(),
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    private function createTicket(Client $client, string $title, string $type): array
    {
        $client->request('POST', '/feedback', [
            'json' => ['title' => $title, 'description' => 'Some description', 'type' => $type],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        return $this->body($client);
    }

    /**
     * @return array<int, mixed>
     */
    private function members(Client $client): array
    {
        $body = $this->body($client);
        $member = $body['member'] ?? $body['hydra:member'] ?? [];
        $this->assertIsArray($member);

        return array_values($member);
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

    /**
     * @param list<string> $roles
     */
    private function makeUser(string $email, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
