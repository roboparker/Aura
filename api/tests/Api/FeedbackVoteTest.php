<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Feedback;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class FeedbackVoteTest extends ApiTestCase
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
        $this->em->createQuery('DELETE FROM App\Entity\Feedback')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testVoteToggleClearsAndFlips(): void
    {
        $user = $this->makeUser('voter@example.com');
        $feedback = $this->makeFeedback($user);
        $client = static::createClient();
        $client->loginUser($user);

        $voteUrl = '/feedback/' . $feedback->getId() . '/vote';

        // Up-vote → created.
        $body = $this->vote($client, $voteUrl, 1);
        $this->assertSame('added', $this->stringField($body, 'status'));
        $this->assertSame(1, $this->intField($body, 'myVote'));
        $this->assertSame(1, $this->intField($body, 'score'));

        // Same direction again → cleared.
        $body = $this->vote($client, $voteUrl, 1);
        $this->assertSame('cleared', $this->stringField($body, 'status'));
        $this->assertSame(0, $this->intField($body, 'myVote'));
        $this->assertSame(0, $this->intField($body, 'score'));

        // Up again, then flip to down → updated.
        $this->vote($client, $voteUrl, 1);
        $body = $this->vote($client, $voteUrl, -1);
        $this->assertSame('updated', $this->stringField($body, 'status'));
        $this->assertSame(-1, $this->intField($body, 'myVote'));
        $this->assertSame(-1, $this->intField($body, 'score'));
    }

    public function testScoreAggregatesAcrossUsersAndMyVoteIsPerCaller(): void
    {
        $alice = $this->makeUser('alice@example.com');
        $bob = $this->makeUser('bob@example.com');
        $feedback = $this->makeFeedback($alice);
        $voteUrl = '/feedback/' . $feedback->getId() . '/vote';

        $client = static::createClient();
        $client->loginUser($alice);
        $this->vote($client, $voteUrl, 1);

        $client->loginUser($bob);
        $this->vote($client, $voteUrl, 1);

        // Net score is 2; Bob's own vote is +1 on the read.
        $client->request('GET', '/feedback/' . $feedback->getId());
        $this->assertResponseIsSuccessful();
        $body = $this->body($client);
        $this->assertSame(2, $this->intField($body, 'score'));
        $this->assertSame(1, $this->intField($body, 'myVote'));

        // A non-voting reader sees the score but myVote 0.
        $carol = $this->makeUser('carol@example.com');
        $client->loginUser($carol);
        $client->request('GET', '/feedback/' . $feedback->getId());
        $body = $this->body($client);
        $this->assertSame(2, $this->intField($body, 'score'));
        $this->assertSame(0, $this->intField($body, 'myVote'));
    }

    public function testInvalidVoteValueRejected(): void
    {
        $user = $this->makeUser('voter@example.com');
        $feedback = $this->makeFeedback($user);
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/feedback/' . $feedback->getId() . '/vote', [
            'json' => ['value' => 5],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testVoteOnUnknownTicketIs404(): void
    {
        $user = $this->makeUser('voter@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/feedback/019ef0eb-0000-7000-8000-000000000000/vote', [
            'json' => ['value' => 1],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAnonymousCannotVote(): void
    {
        $user = $this->makeUser('voter@example.com');
        $feedback = $this->makeFeedback($user);
        $client = static::createClient();

        $client->request('POST', '/feedback/' . $feedback->getId() . '/vote', [
            'json' => ['value' => 1],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function vote(Client $client, string $url, int $value): array
    {
        $client->request('POST', $url, [
            'json' => ['value' => $value],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        return $this->body($client);
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

    private function makeFeedback(User $owner): Feedback
    {
        $feedback = new Feedback();
        $feedback->setOwner($owner);
        $feedback->setTitle('Votable ticket');
        $feedback->setDescription('Body');
        $feedback->setType(Feedback::TYPE_FEATURE);
        $feedback->setTicketNumber(
            static::getContainer()->get(\App\Repository\FeedbackRepository::class)->nextTicketNumber(),
        );
        $this->em->persist($feedback);
        $this->em->flush();

        return $feedback;
    }

    private function makeUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
