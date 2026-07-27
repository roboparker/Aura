<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use App\Service\AvatarColorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tags are space-scoped (#tags): every member of a space shares its tag pool.
 * Access is enforced by TagAccessExtension (space membership) — non-members
 * can't see, fetch, edit, delete, or attach a space's tags.
 */
class TagTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        // Children first, then spaces, then users. DB-level ON DELETE CASCADE
        // also covers task_tag / membership rows.
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Tag')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListTagsUnauthenticated(): void
    {
        static::createClient()->request('GET', '/tags');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateTagInOwnSpace(): void
    {
        $user = $this->createUser('alice@example.com');
        $space = $this->createSpace($user, 'Alice space');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/tags', [
            'json' => [
                'title' => 'Urgent',
                'description' => 'Needs doing today',
                'color' => '#b91c1c',
                'space' => '/spaces/' . $space->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'Tag',
            'title' => 'Urgent',
            'description' => 'Needs doing today',
            'color' => '#b91c1c',
        ]);

        $tag = $this->reloadTagByTitle('Urgent');
        $this->assertTrue($user->getId()?->equals($tag->getOwner()?->getId()));
        $this->assertTrue($space->getId()?->equals($tag->getSpace()?->getId()));
    }

    public function testCreateTagIgnoresClientSuppliedOwner(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Alice space');

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('POST', '/tags', [
            'json' => [
                'title' => 'Sneaky',
                'color' => '#15803d',
                'space' => '/spaces/' . $space->getId(),
                'owner' => '/users/' . $bob->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $tag = $this->reloadTagByTitle('Sneaky');
        $this->assertTrue($alice->getId()?->equals($tag->getOwner()?->getId()));
    }

    public function testCreateTagInSpaceYouDontBelongToIsForbidden(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsSpace = $this->createSpace($bob, 'Bob space');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tags', [
            'json' => [
                'title' => 'Trespass',
                'color' => '#15803d',
                'space' => '/spaces/' . $bobsSpace->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        // Alice can't see Bob's space, so the IRI doesn't resolve (400) —
        // existence-hiding before the space-membership security gate.
        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateTagRequiresSpace(): void
    {
        $user = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/tags', [
            'json' => ['title' => 'No space', 'color' => '#15803d'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        // securityPostDenormalize rejects a tag with no (accessible) space.
        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateTagRequiresTitle(): void
    {
        $user = $this->createUser('alice@example.com');
        $space = $this->createSpace($user, 'Alice space');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/tags', [
            'json' => ['title' => '', 'color' => '#15803d', 'space' => '/spaces/' . $space->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCreateTagRejectsInvalidColor(): void
    {
        $user = $this->createUser('alice@example.com');
        $space = $this->createSpace($user, 'Alice space');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/tags', [
            'json' => ['title' => 'Bad', 'color' => 'red', 'space' => '/spaces/' . $space->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    /**
     * A well-formed hex outside the WCAG-AA palette is rejected. Tag chips
     * render their label over this colour, and an unconstrained hex let
     * seeded tags reach 2.15:1 against white — well under the 4.5:1 floor.
     */
    public function testCreateTagRejectsColorOutsideThePalette(): void
    {
        $user = $this->createUser('alice@example.com');
        $space = $this->createSpace($user, 'Alice space');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/tags', [
            // amber-500: valid hex, 2.15:1 against white.
            'json' => ['title' => 'Low contrast', 'color' => '#f59e0b', 'space' => '/spaces/' . $space->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    /** Every palette entry is accepted, so the constraint can't drift from the list. */
    public function testCreateTagAcceptsEveryPaletteColor(): void
    {
        $user = $this->createUser('alice@example.com');
        $space = $this->createSpace($user, 'Alice space');
        $client = static::createClient();
        $client->loginUser($user);

        foreach (AvatarColorService::PALETTE as $index => $color) {
            $client->request('POST', '/tags', [
                'json' => [
                    'title' => 'Palette ' . $index,
                    'color' => $color,
                    'space' => '/spaces/' . $space->getId(),
                ],
                'headers' => ['Content-Type' => 'application/ld+json'],
            ]);
            $this->assertResponseStatusCodeSame(201, sprintf('palette colour %s should be accepted', $color));
        }
    }

    public function testListTagsOnlyShowsTagsInSpacesYouBelongTo(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceSpace = $this->createSpace($alice, 'Alice space');
        $bobSpace = $this->createSpace($bob, 'Bob space');

        $this->createTag($alice, $aliceSpace, 'A1');
        $this->createTag($alice, $aliceSpace, 'A2');
        $this->createTag($bob, $bobSpace, 'B1');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tags');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);
    }

    public function testFilterTagsBySpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $first = $this->createSpace($alice, 'First');
        $second = $this->createSpace($alice, 'Second');
        $this->createTag($alice, $first, 'F1');
        $this->createTag($alice, $first, 'F2');
        $this->createTag($alice, $second, 'S1');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tags?space=/spaces/' . $first->getId());
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);
    }

    public function testGetTagInInaccessibleSpaceReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobSpace = $this->createSpace($bob, 'Bob space');
        $bobsTag = $this->createTag($bob, $bobSpace, 'Bob only');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tags/' . $bobsTag->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAnySpaceMemberCanUpdateTag(): void
    {
        $owner = $this->createUser('alice@example.com');
        $member = $this->createUser('bob@example.com');
        $space = $this->createSpace($owner, 'Shared');
        $this->ensureSpaceMembership($space, $member);
        $tag = $this->createTag($owner, $space, 'Before');

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('PATCH', '/tags/' . $tag->getId(), [
            'json' => ['title' => 'After', 'color' => '#1d4ed8'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $reloaded = $this->reloadTagByTitle('After');
        $this->assertSame('#1d4ed8', $reloaded->getColor());
    }

    public function testDeleteTagInOwnSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Alice space');
        $tag = $this->createTag($alice, $space, 'Delete me');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/tags/' . $tag->getId());
        $this->assertResponseStatusCodeSame(204);
    }

    public function testAddTagToTaskViaPatch(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Alice space');
        $task = $this->createTask($alice, 'Write docs');
        $tag = $this->createTag($alice, $space, 'Writing', '#1d4ed8');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['tags' => ['/tags/' . $tag->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(1, $reloaded->getTags());
        $firstTag = $reloaded->getTags()->first();
        self::assertNotFalse($firstTag);
        $this->assertSame('Writing', $firstTag->getTitle());
    }

    public function testRemoveTagFromTaskViaPatch(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Alice space');
        $task = $this->createTask($alice, 'Write docs');
        $keep = $this->createTag($alice, $space, 'Keep', '#15803d');
        $drop = $this->createTag($alice, $space, 'Drop', '#b91c1c');
        $task->addTag($keep);
        $task->addTag($drop);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['tags' => ['/tags/' . $keep->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(1, $reloaded->getTags());
        $firstTag = $reloaded->getTags()->first();
        self::assertNotFalse($firstTag);
        $this->assertSame('Keep', $firstTag->getTitle());
    }

    public function testCannotAttachTagFromInaccessibleSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobSpace = $this->createSpace($bob, 'Bob space');
        $aliceTask = $this->createTask($alice, 'Alice task');
        $bobsTag = $this->createTag($bob, $bobSpace, 'Bob only');

        $client = static::createClient();
        $client->loginUser($alice);
        // TagAccessExtension scopes item lookups, so the IRI resolves to no
        // row for Alice and the deserializer rejects the reference.
        $client->request('PATCH', '/tasks/' . $aliceTask->getId(), [
            'json' => ['tags' => ['/tags/' . $bobsTag->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testTagAppearsOnTaskCollection(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Alice space');
        $task = $this->createTask($alice, 'Visible task');
        $tag = $this->createTag($alice, $space, 'Badge', '#f59e0b');
        $task->addTag($tag);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();

        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
        $task = $members[0] ?? null;
        $this->assertIsArray($task);
        $tags = $task['tags'] ?? null;
        $this->assertIsArray($tags);
        $this->assertCount(1, $tags);
        $firstTag = $tags[0] ?? null;
        $this->assertIsArray($firstTag);
        $this->assertSame('Badge', $firstTag['title']);
        $this->assertSame('#f59e0b', $firstTag['color']);
    }

    public function testDeletingTagRemovesItFromTasks(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Alice space');
        $task = $this->createTask($alice, 'Has a tag');
        $tag = $this->createTag($alice, $space, 'Temp', '#b91c1c');
        $task->addTag($tag);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/tags/' . $tag->getId());
        $this->assertResponseStatusCodeSame(204);

        // FK cascade on task_tag removes the association; task remains.
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(0, $reloaded->getTags());
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

    private function createSpace(User $owner, string $name): Space
    {
        $space = new Space();
        $space->setName($name);
        $space->setCreatedBy($owner);
        $this->entityManager->persist($space);

        $admin = (new SpaceMembership())
            ->setUser($owner)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($admin);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        return $space;
    }

    private function ensureSpaceMembership(
        Space $space,
        User $user,
        string $role = Space::ROLE_MEMBER,
    ): void {
        $membership = (new SpaceMembership())
            ->setUser($user)
            ->setRole($role);
        $space->addUserMembership($membership);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
    }

    private function createTag(
        User $owner,
        Space $space,
        string $title,
        string $color = '#6b7280',
    ): Tag {
        $tag = new Tag();
        $tag->setOwner($owner);
        $tag->setSpace($space);
        $tag->setTitle($title);
        $tag->setColor($color);

        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    private function createTask(User $owner, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    private function reloadTagByTitle(string $title): Tag
    {
        $this->entityManager->clear();
        $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['title' => $title]);
        self::assertNotNull($tag, sprintf('Expected to find Tag with title "%s".', $title));
        return $tag;
    }
}
