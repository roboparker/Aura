<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\CustomField\CustomFieldKind;
use App\Entity\CustomFieldDefinition;
use App\Entity\Board;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Edge-branch coverage for App\Controller\CustomFieldDefinitionReorderController.
 * The base CustomFieldDefinitionReorderTest covers admin happy-path,
 * non-admin 404, incomplete + duplicate payloads. This adds: unknown /
 * malformed board id, missing `order` key, malformed IRI, and an IRI
 * that belongs to another board.
 */
class CustomFieldDefinitionReorderEdgeTest extends ApiTestCase
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testUnknownProjectIs404(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/00000000-0000-0000-0000-000000000000/custom_field_definitions/reorder', [
            'json' => ['order' => []],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidProjectIdIs404(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/not-a-uuid/custom_field_definitions/reorder', [
            'json' => ['order' => []],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testMissingOrderKeyIs400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/custom_field_definitions/reorder', [
            'json' => ['nope' => []],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testMalformedIriIs400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->createProject($alice, 'Backend');
        $this->seedField($board, 'Alpha', 0);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/boards/' . $board->getId() . '/custom_field_definitions/reorder', [
            'json' => ['order' => ['/custom_field_definitions/not-a-uuid']],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testIriFromAnotherProjectIs400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $boardA = $this->createProject($alice, 'Alpha board');
        $boardB = $this->createProject($alice, 'Beta board');
        // One field on each board.
        $this->seedField($boardA, 'Local', 0);
        $foreign = $this->seedField($boardB, 'Foreign', 0);

        $client = static::createClient();
        $client->loginUser($alice);
        // Reorder board A but list board B's field — rejected (not part
        // of this board / contiguity guard).
        $client->request('POST', '/boards/' . $boardA->getId() . '/custom_field_definitions/reorder', [
            'json' => ['order' => ['/custom_field_definitions/' . $foreign->getId()]],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    private function createProject(User $owner, string $title): Board
    {
        $board = new Board();
        $board->setOwner($owner);
        $board->setTitle($title);
        $this->addBoardMember($board, $owner);
        $this->entityManager->persist($board);
        $this->entityManager->flush();
        return $board;
    }

    private function createUser(string $email): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);
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

    private function seedField(Board $board, string $name, int $position): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setBoard($board)
            ->setName($name)
            ->setKind(CustomFieldKind::TEXT->value)
            ->setSubtype('text')
            ->setConfig(['multi' => false])
            ->setPosition($position);
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }
}
