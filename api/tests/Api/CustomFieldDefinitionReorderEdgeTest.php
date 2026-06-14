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
 * Edge-branch coverage for App\Controller\CustomFieldDefinitionReorderController.
 * The base CustomFieldDefinitionReorderTest covers admin happy-path,
 * non-admin 404, incomplete + duplicate payloads. This adds: unknown /
 * malformed project id, missing `order` key, malformed IRI, and an IRI
 * that belongs to another project.
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testUnknownProjectIs404(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/projects/00000000-0000-0000-0000-000000000000/custom_field_definitions/reorder', [
            'json' => ['order' => []],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidProjectIdIs404(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/projects/not-a-uuid/custom_field_definitions/reorder', [
            'json' => ['order' => []],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testMissingOrderKeyIs400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/projects/' . $project->getId() . '/custom_field_definitions/reorder', [
            'json' => ['nope' => []],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testMalformedIriIs400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backend');
        $this->seedField($project, 'Alpha', 0);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/projects/' . $project->getId() . '/custom_field_definitions/reorder', [
            'json' => ['order' => ['/custom_field_definitions/not-a-uuid']],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testIriFromAnotherProjectIs400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $projectA = $this->createProject($alice, 'Alpha project');
        $projectB = $this->createProject($alice, 'Beta project');
        // One field on each project.
        $this->seedField($projectA, 'Local', 0);
        $foreign = $this->seedField($projectB, 'Foreign', 0);

        $client = static::createClient();
        $client->loginUser($alice);
        // Reorder project A but list project B's field — rejected (not part
        // of this project / contiguity guard).
        $client->request('POST', '/projects/' . $projectA->getId() . '/custom_field_definitions/reorder', [
            'json' => ['order' => ['/custom_field_definitions/' . $foreign->getId()]],
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
