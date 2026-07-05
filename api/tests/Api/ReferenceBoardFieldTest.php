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
 * `reference.board` custom field (the strategy added for the schema
 * redesign). A board reference is valid only when the target board
 * lives in the field's space; cross-space targets are rejected behind
 * the same "does not exist" message the other reference kinds use.
 */
class ReferenceBoardFieldTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldValue')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testInSpaceProjectReferenceAccepted(): void
    {
        $alice = $this->createUser('alice@example.com');
        $home = $this->createProject($alice, 'Home');
        $sibling = $this->createProject($alice, 'Sibling'); // same personal space
        $field = $this->seedProjectReference($home, 'Related');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Linked task',
                'board' => '/boards/' . $home->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $field->getId(), 'value' => '/boards/' . $sibling->getId()],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testCrossSpaceProjectReferenceRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $home = $this->createProject($alice, 'Home');
        $foreign = $this->createProject($bob, 'Foreign'); // bob's personal space
        $field = $this->seedProjectReference($home, 'Related');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Linked task',
                'board' => '/boards/' . $home->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $field->getId(), 'value' => '/boards/' . $foreign->getId()],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
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

    private function seedProjectReference(Board $board, string $name): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setBoard($board)
            ->setName($name)
            ->setKind(CustomFieldKind::REFERENCE->value)
            ->setSubtype('board')
            ->setConfig(['multi' => false])
            ->setNullable(true);
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }
}
