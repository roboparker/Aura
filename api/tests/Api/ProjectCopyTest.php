<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `POST /projects/{id}/copy` — clones a project (+ CFDs) into a
 * target space (#182).
 */
class ProjectCopyTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRequiresAuth(): void
    {
        static::createClient()->request('POST', '/projects/' . str_repeat('0', 36) . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCopiesIntoSourceSpaceByDefault(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $project = $this->createProject($alice, 'Template', $source);
        $this->seedDefinition($project, 'Severity', 'dropdown', ['low', 'med', 'high']);
        $this->seedDefinition($project, 'Notes', 'text');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/projects/' . $project->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $client->getResponse()->toArray();
        $this->assertSame('Template (copy)', $body['title']);
        $this->assertSame('/spaces/' . $source->getId(), $body['space']);

        $this->entityManager->clear();
        $copyId = $body['id'];
        $copy = $this->entityManager->getRepository(Project::class)->find($copyId);
        $this->assertNotNull($copy);
        $this->assertSame((string) $source->getId(), (string) $copy->getSpace()->getId());
        $this->assertSame((string) $alice->getId(), (string) $copy->getOwner()->getId());

        // CFDs cloned with same shape, attached to the new project.
        $defs = $this->entityManager->getRepository(CustomFieldDefinition::class)
            ->findBy(['project' => $copy], ['position' => 'ASC']);
        $this->assertCount(2, $defs);
        $this->assertSame('Severity', $defs[0]->getName());
        $this->assertSame('dropdown', $defs[0]->getType());
        $this->assertSame(['low', 'med', 'high'], $defs[0]->getOptions());
        $this->assertSame('Notes', $defs[1]->getName());

        // Source project is untouched (its 2 CFDs still there).
        $sourceDefs = $this->entityManager->getRepository(CustomFieldDefinition::class)
            ->findBy(['project' => $project]);
        $this->assertCount(2, $sourceDefs);
    }

    public function testCopiesIntoExplicitTargetSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $project = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/projects/' . $project->getId() . '/copy', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $client->getResponse()->toArray();
        $this->assertSame('/spaces/' . $target->getId(), $body['space']);

        $this->entityManager->clear();
        $copy = $this->entityManager->getRepository(Project::class)->find($body['id']);
        $this->assertSame((string) $target->getId(), (string) $copy->getSpace()->getId());
    }

    public function testCopyDoesNotDoubleSuffix(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $project = $this->createProject($alice, 'Template (copy)', $source);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/projects/' . $project->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('Template (copy)', $client->getResponse()->toArray()['title']);
    }

    public function testCloneOwnerIsCurrentUserNotSourceOwner(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $source = $this->createSpace($alice, 'Shared');
        $this->ensureSpaceMembership($source, $bob);
        $project = $this->createProject($alice, 'Alice template', $source);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/projects/' . $project->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $copy = $this->entityManager->getRepository(Project::class)
            ->find($client->getResponse()->toArray()['id']);
        $this->assertSame((string) $bob->getId(), (string) $copy->getOwner()->getId());
    }

    public function testNonMemberOfSourceGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $source = $this->createSpace($alice, 'Source');
        $project = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', '/projects/' . $project->getId() . '/copy', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonMemberOfTargetGets404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $source = $this->createSpace($alice, 'Source');
        $this->ensureSpaceMembership($source, $bob);
        $target = $this->createSpace($alice, 'Target');
        $project = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/projects/' . $project->getId() . '/copy', [
            'json' => ['space' => '/spaces/' . $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidSpaceIriGets400(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $project = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/projects/' . $project->getId() . '/copy', [
            'json' => ['space' => 'not-a-real-iri'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testAcceptsBareUuidAsTarget(): void
    {
        $alice = $this->createUser('alice@example.com');
        $source = $this->createSpace($alice, 'Source');
        $target = $this->createSpace($alice, 'Target');
        $project = $this->createProject($alice, 'Backend', $source);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/projects/' . $project->getId() . '/copy', [
            'json' => ['space' => (string) $target->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('/spaces/' . $target->getId(), $client->getResponse()->toArray()['space']);
    }

    /**
     * @param string[]|null $options
     */
    private function seedDefinition(Project $project, string $name, string $type, ?array $options = null): CustomFieldDefinition
    {
        $def = new CustomFieldDefinition();
        $def->setProject($project);
        $def->setName($name);
        $def->setType($type);
        if (null !== $options) {
            $def->setOptions($options);
        }
        $def->setPosition(0);
        $this->entityManager->persist($def);
        $this->entityManager->flush();
        return $def;
    }

    private function createProject(User $owner, string $title, Space $space): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        $project->setSpace($space);
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        return $project;
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
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
