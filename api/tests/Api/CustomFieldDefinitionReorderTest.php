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
 * Reorder endpoint: `POST /projects/{id}/custom_field_definitions/reorder`.
 * Covers the space-admin gate, the contiguity guard, and the happy-path
 * renumbering that drives column order on the task list.
 */
class CustomFieldDefinitionReorderTest extends ApiTestCase
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAdminReordersDefinitions(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $a = $this->seedField($project, 'Alpha', 0);
        $b = $this->seedField($project, 'Bravo', 1);
        $c = $this->seedField($project, 'Charlie', 2);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/projects/' . $project->getId() . '/custom_field_definitions/reorder', [
            'json' => [
                'order' => [
                    '/custom_field_definitions/' . $c->getId(),
                    '/custom_field_definitions/' . $a->getId(),
                    '/custom_field_definitions/' . $b->getId(),
                ],
            ],
        ]);
        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(CustomFieldDefinition::class)
            ->findBy(['project' => $project], ['position' => 'ASC']);
        $order = array_map(static fn (CustomFieldDefinition $d): string => $d->getName(), $reloaded);
        $this->assertSame(['Charlie', 'Alpha', 'Bravo'], $order);
    }

    public function testMemberCannotReorder(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Backend');
        $this->addProjectMember($project, $bob);
        $a = $this->seedField($project, 'Alpha', 0);
        $b = $this->seedField($project, 'Bravo', 1);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/projects/' . $project->getId() . '/custom_field_definitions/reorder', [
            'json' => [
                'order' => [
                    '/custom_field_definitions/' . $b->getId(),
                    '/custom_field_definitions/' . $a->getId(),
                ],
            ],
        ]);
        // Non-admins (and non-members) get 404 to match the existence-hiding shape.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testIncompletePayloadRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $a = $this->seedField($project, 'Alpha', 0);
        $this->seedField($project, 'Bravo', 1);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/projects/' . $project->getId() . '/custom_field_definitions/reorder', [
            'json' => ['order' => ['/custom_field_definitions/' . $a->getId()]],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testDuplicateIriRejected(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $a = $this->seedField($project, 'Alpha', 0);
        $this->seedField($project, 'Bravo', 1);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/projects/' . $project->getId() . '/custom_field_definitions/reorder', [
            'json' => [
                'order' => [
                    '/custom_field_definitions/' . $a->getId(),
                    '/custom_field_definitions/' . $a->getId(),
                ],
            ],
        ]);
        $this->assertResponseStatusCodeSame(400);
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

    private function seedField(Project $project, string $name, int $position): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setProject($project)
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
