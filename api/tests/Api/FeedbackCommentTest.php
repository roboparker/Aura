<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Comment;
use App\Entity\Feedback;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers the `feedback` arm of the unified Comment entity (#228): a
 * comment can hang off a feedback ticket, every authenticated user can
 * read it (the board is instance-level), the author can edit, and a
 * platform admin can delete.
 */
class FeedbackCommentTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Feedback')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testCommentOnFeedbackCreatesAndSetsDiscriminator(): void
    {
        $user = $this->makeUser('user@example.com');
        $feedback = $this->makeFeedback($user);
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/comments', [
            'json' => ['feedback' => '/feedback/' . $feedback->getId(), 'body' => 'Great idea!'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $body = $this->body($client);
        $this->assertSame('feedback', $this->stringField($body, 'commentableType'));
    }

    public function testAnyAuthenticatedUserCanReadFeedbackComments(): void
    {
        $author = $this->makeUser('author@example.com');
        $feedback = $this->makeFeedback($author);
        $client = static::createClient();
        $client->loginUser($author);
        $client->request('POST', '/comments', [
            'json' => ['feedback' => '/feedback/' . $feedback->getId(), 'body' => 'First'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // A different user (no shared space, no membership) can list it —
        // the board is instance-level.
        $other = $this->makeUser('other@example.com');
        $client->loginUser($other);
        $client->request('GET', '/comments?feedback=/feedback/' . $feedback->getId());
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $this->members($client));
    }

    public function testAuthorEditsAndAdminDeletes(): void
    {
        $author = $this->makeUser('author@example.com');
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $feedback = $this->makeFeedback($author);

        $client = static::createClient();
        $client->loginUser($author);
        $client->request('POST', '/comments', [
            'json' => ['feedback' => '/feedback/' . $feedback->getId(), 'body' => 'Typo here'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $iri = $this->stringField($this->body($client), '@id');

        // Author edits.
        $client->request('PATCH', $iri, [
            'json' => ['body' => 'Fixed'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Fixed', $this->stringField($this->body($client), 'body'));

        // Admin deletes.
        $client->loginUser($admin);
        $client->request('DELETE', $iri);
        $this->assertResponseStatusCodeSame(204);
    }

    public function testFeedbackReadExposesCommentCount(): void
    {
        $user = $this->makeUser('user@example.com');
        $feedback = $this->makeFeedback($user);
        $client = static::createClient();
        $client->loginUser($user);

        foreach (['one', 'two'] as $text) {
            $client->request('POST', '/comments', [
                'json' => ['feedback' => '/feedback/' . $feedback->getId(), 'body' => $text],
                'headers' => ['Content-Type' => 'application/ld+json'],
            ]);
        }

        $client->request('GET', '/feedback/' . $feedback->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSame(2, $this->intField($this->body($client), 'commentCount'));
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

    private function makeFeedback(User $owner): Feedback
    {
        $feedback = new Feedback();
        $feedback->setOwner($owner);
        $feedback->setTitle('Commentable ticket');
        $feedback->setDescription('Body');
        $feedback->setType(Feedback::TYPE_BUG);
        $feedback->setTicketNumber(
            static::getContainer()->get(\App\Repository\FeedbackRepository::class)->nextTicketNumber(),
        );
        $this->em->persist($feedback);
        $this->em->flush();

        return $feedback;
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
