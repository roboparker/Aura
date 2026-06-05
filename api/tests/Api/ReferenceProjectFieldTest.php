<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\CustomField\CustomFieldKind;
use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `reference.project` custom field (the strategy added for the schema
 * redesign). A project reference is valid only when the target project
 * lives in the field's space; cross-space targets are rejected behind
 * the same "does not exist" message the other reference kinds use.
 */
class ReferenceProjectFieldTest extends ApiTestCase
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
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
                'project' => '/projects/' . $home->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $field->getId(), 'value' => '/projects/' . $sibling->getId()],
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
                'project' => '/projects/' . $home->getId(),
                'customFieldValues' => [
                    ['definition' => '/custom_field_definitions/' . $field->getId(), 'value' => '/projects/' . $foreign->getId()],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    private function createProject(User $owner, string $title): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        $this->addProjectMember($project, $owner);
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        return $project;
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

    private function seedProjectReference(Project $project, string $name): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setProject($project)
            ->setName($name)
            ->setKind(CustomFieldKind::REFERENCE->value)
            ->setSubtype('project')
            ->setConfig(['multi' => false])
            ->setNullable(true);
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }
}
