<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Comment;
use App\Entity\Discussion;
use App\Entity\Notification;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers the discussion arm of the unified comment stack (#228 forum
 * surface): posting/reading/deleting replies, space-scoped access,
 * author-vs-admin delete escalation, the locked-thread post block,
 * mentions, and the read-time reply aggregates.
 */
class DiscussionCommentTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Notification')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Discussion')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testMemberCanCommentOnDiscussion(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Marketing');
        $this->ensureSpaceMembership($space, $bob);
        $discussion = $this->seedDiscussion($alice, $space, 'Launch tone');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/comments', [
            'json' => [
                'discussion' => '/discussions/' . $discussion->getId(),
                'body' => 'I vote playful.',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'Comment',
            'commentableType' => 'discussion',
            'body' => 'I vote playful.',
        ]);

        // Author stamped server-side.
        $row = $this->entityManager->getRepository(Comment::class)
            ->findOneBy(['body' => 'I vote playful.']);
        $this->assertNotNull($row);
        $this->assertSame((string) $bob->getId(), (string) $row->getAuthor()?->getId());
    }

    public function testNonMemberCannotCommentOnDiscussion(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSpace($alice, 'Marketing');
        $discussion = $this->seedDiscussion($alice, $space, 'Hidden thread');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', '/comments', [
            'json' => [
                'discussion' => '/discussions/' . $discussion->getId(),
                'body' => 'Sneaking in.',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        // DiscussionAccessExtension hides the thread during IRI
        // resolution, so denormalization fails before the security
        // expression runs — same 400 shape as the discussion create test.
        $this->assertResponseStatusCodeSame(400);
    }

    public function testListFiltersToReadableDiscussionComments(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceSpace = $this->createSpace($alice, 'Alice');
        $bobSpace = $this->createSpace($bob, 'Bob');
        $aliceDisc = $this->seedDiscussion($alice, $aliceSpace, 'A');
        $bobDisc = $this->seedDiscussion($bob, $bobSpace, 'B');
        $this->seedComment($alice, $aliceDisc, 'Visible.');
        $this->seedComment($bob, $bobDisc, 'Hidden.');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/comments?discussion=/discussions/' . $aliceDisc->getId());

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? [];
        $this->assertIsArray($members);
        $bodies = array_map(
            static fn (mixed $c): mixed => is_array($c) ? $c['body'] ?? null : null,
            $members,
        );
        $this->assertSame(['Visible.'], $bodies);
    }

    public function testStrangerCannotReadDiscussionComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSpace($alice, 'Marketing');
        $discussion = $this->seedDiscussion($alice, $space, 'Private');
        $comment = $this->seedComment($alice, $discussion, 'Secret.');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/comments/' . $comment->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testAuthorCanDeleteOwnDiscussionComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Marketing');
        $this->ensureSpaceMembership($space, $bob);
        $discussion = $this->seedDiscussion($alice, $space, 'Thread');
        $comment = $this->seedComment($bob, $discussion, 'Mine.');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('DELETE', '/comments/' . $comment->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testSpaceAdminCanDeleteAnyDiscussionComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Marketing');
        $this->ensureSpaceMembership($space, $bob);
        $discussion = $this->seedDiscussion($alice, $space, 'Thread');
        $bobsComment = $this->seedComment($bob, $discussion, 'Off-topic.');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/comments/' . $bobsComment->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testNonAdminMemberCannotDeleteAnothersDiscussionComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Marketing');
        $this->ensureSpaceMembership($space, $bob);
        $discussion = $this->seedDiscussion($alice, $space, 'Thread');
        $aliceComment = $this->seedComment($alice, $discussion, 'Admin note.');

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('DELETE', '/comments/' . $aliceComment->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLockedDiscussionRejectsNewComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Marketing');
        $discussion = $this->seedDiscussion($alice, $space, 'Closed thread');
        $discussion->setIsLocked(true);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'discussion' => '/discussions/' . $discussion->getId(),
                'body' => 'One more thing…',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);

        $this->entityManager->clear();
        $this->assertSame(
            0,
            (int) $this->entityManager->createQuery(
                'SELECT COUNT(c) FROM App\Entity\Comment c WHERE c.discussion = :d',
            )->setParameter('d', $discussion->getId())->getSingleScalarResult(),
        );
    }

    public function testExistingCommentOnLockedThreadStaysEditable(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Marketing');
        $discussion = $this->seedDiscussion($alice, $space, 'Thread');
        $comment = $this->seedComment($alice, $discussion, 'Before lock.');
        $discussion->setIsLocked(true);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/comments/' . $comment->getId(), [
            'json' => ['body' => 'Tidied up after lock.'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testMentionNotifiesSpaceMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Marketing');
        $this->ensureSpaceMembership($space, $bob);
        $discussion = $this->seedDiscussion($alice, $space, 'Thread');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/comments', [
            'json' => [
                'discussion' => '/discussions/' . $discussion->getId(),
                'body' => 'What do you think @bob?',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(Notification::class)->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame(Notification::TYPE_MENTION, $rows[0]->getType());
        $this->assertSame((string) $bob->getId(), (string) $rows[0]->getRecipient()?->getId());
    }

    public function testDiscussionItemExposesReplyAggregates(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Marketing');
        $this->ensureSpaceMembership($space, $bob);
        $discussion = $this->seedDiscussion($alice, $space, 'Thread');
        $this->seedComment($alice, $discussion, 'First (author).');
        $this->seedComment($bob, $discussion, 'Reply.');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/discussions/' . $discussion->getId());

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();

        $this->assertSame(2, $body['commentCount'] ?? null);
        $this->assertSame(2, $body['participantCount'] ?? null);
        $this->assertArrayHasKey('lastActivityAt', $body);

        $participants = $body['participants'] ?? [];
        $this->assertIsArray($participants);
        $emails = array_map(
            static fn (mixed $p): mixed => is_array($p) ? $p['email'] ?? null : null,
            $participants,
        );
        sort($emails);
        $this->assertSame(['alice@example.com', 'bob@example.com'], $emails);
    }

    public function testDiscussionCollectionExposesReplyCount(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Marketing');
        $discussion = $this->seedDiscussion($alice, $space, 'Thread');
        $this->seedComment($alice, $discussion, 'A reply.');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/discussions');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? [];
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
        $first = $members[0];
        $this->assertIsArray($first);
        $this->assertSame(1, $first['commentCount'] ?? null);
    }

    private function seedDiscussion(User $author, Space $space, string $title): Discussion
    {
        $disc = new Discussion();
        $disc->setSpace($space);
        $disc->setAuthor($author);
        $disc->setTitle($title);
        $disc->setBody('Body for ' . $title);
        $disc->setCategory('general');
        $this->entityManager->persist($disc);
        $this->entityManager->flush();
        return $disc;
    }

    private function seedComment(User $author, Discussion $discussion, string $body): Comment
    {
        $c = new Comment();
        $c->setAuthor($author);
        $c->setDiscussion($discussion);
        $c->setBody($body);
        $this->entityManager->persist($c);
        $this->entityManager->flush();
        return $c;
    }

    private function createSpace(User $admin, string $name): Space
    {
        $space = new Space();
        $space->setName($name);
        $space->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $membership = (new SpaceMembership())
            ->setUser($admin)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($membership);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
        return $space;
    }

    /**
     * @param string[] $roles
     */
    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
