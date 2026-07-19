<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\CustomFieldDefinition;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `POST /boards/{id}/move` — relocates a board (plus its
 * denormalised child rows) into a different space (#182).
 */
class BoardMoveTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRequiresAuth(): void
    {
        static::createClient()->request('POST', '/boards/' . str_repeat('0', 36) . '/move', [
            'json' => ['space' => '/spaces/x'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testMemberOfBothSpacesCanMoveProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $board = $this->createProject($alice, 'Backend', $source);
        $this->seedDefinition($board, 'Severity');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/move', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertTrue($response->toArray()['moved']);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Board::class)->find($board->getId());
        $this->assertNotNull($reloaded);
        $this->assertSame((string) $target->getId(), (string) $reloaded->getSpace()?->getId());

        // Custom fields are space-owned (#custom-fields-space): a board's
        // field selections belong to the SOURCE space, so the move detaches
        // them — the board starts fresh in the target and opts into the
        // target's own fields afterwards. The field itself stays in the
        // source space.
        /** @var list<CustomFieldDefinition> $attached */
        $attached = $this->entityManager->createQuery(
            'SELECT d FROM App\Entity\CustomFieldDefinition d JOIN d.boards p WHERE p = :board',
        )->setParameter('board', $reloaded)->getResult();
        $this->assertCount(0, $attached);

        $sourceFields = $this->entityManager->getRepository(CustomFieldDefinition::class)
            ->findBy(['space' => $source]);
        $this->assertCount(1, $sourceFields);
    }

    public function testNonMemberOfSourceGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($stranger, 'Target');
        $board = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', '/boards/' . $board->getId() . '/move', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        // Stranger can't see the board at all — same shape as the
        // access extension hiding it from listings.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonMemberOfTargetGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $source = $this->createSpace($alice, 'Source');
        $this->ensureSpaceMembership($source, $bob);
        $target = $this->createSpace($alice, 'Target');
        // Bob is in the source but not the target.
        $board = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/boards/' . $board->getId() . '/move', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testMissingSpacePayloadGets400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $board = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/move', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testInvalidSpaceIriGets400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $board = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/move', [
            'json' => ['space' => 'not-an-iri-or-uuid'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testMoveToCurrentSpaceIsNoOp(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $board = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/move', [
            'json' => ['space' => '/spaces/' . $source->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertFalse($response->toArray()['moved']);
    }

    public function testAcceptsBareUuidAsTarget(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $board = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/move', [
            'json' => ['space' => (string) $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertTrue($response->toArray()['moved']);
    }

    private function createProject(User $owner, string $title, Space $space): Board
    {
        $board = new Board();
        $board->setOwner($owner);
        $board->setTitle($title);
        $board->setSpace($space);
        $this->entityManager->persist($board);
        $this->entityManager->flush();
        return $board;
    }

    private function seedDefinition(Board $board, string $name): CustomFieldDefinition
    {
        $def = new CustomFieldDefinition();
        $def->setBoard($board);
        $def->setName($name);
        $def->setType('text');
        $def->setPosition(0);
        $this->entityManager->persist($def);
        $this->entityManager->flush();
        return $def;
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
