<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Per-category scoping of an impersonated admin session, driven by the
 * target user's `impersonationAccess` consent matrix and enforced by
 * App\EventListener\AccessPolicyListener.
 */
class ImpersonationAccessTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testNoneCategoryFiltersItemsFromList(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $member = $this->createImpersonableUser('member@example.com', ['tasks' => 'none']);
        $this->createTask($member, 'Member task');

        $client = $this->impersonate($admin, 'member@example.com');
        // Addressable-type lists aren't blocked wholesale — they're filtered
        // to what's viewable. With tasks=none and no overrides, that's empty.
        $client->request('GET', '/tasks');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 0]);
    }

    public function testNoneItemBlocksDirectAccess(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $member = $this->createImpersonableUser('member@example.com', ['tasks' => 'none']);
        $task = $this->createTask($member, 'Member task');

        $client = $this->impersonate($admin, 'member@example.com');
        $client->request('GET', '/tasks/' . $task->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testItemOverrideElevatesAboveNoneCategory(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $member = $this->createUser('member@example.com');
        $granted = $this->createTask($member, 'Granted task');
        $hidden = $this->createTask($member, 'Hidden task');
        // Category none, but one task explicitly elevated to edit.
        $member->setPreferences([
            'canBeImpersonated' => true,
            'impersonationAccess' => ['tasks' => 'none'],
            'impersonationItemAccess' => ['task' => [(string) $granted->getId() => 'edit']],
        ]);
        $this->entityManager->flush();

        $client = $this->impersonate($admin, 'member@example.com');

        // List shows only the elevated task.
        $client->request('GET', '/tasks');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 1]);

        // The elevated task is editable; the other is invisible.
        $client->request('PATCH', '/tasks/' . $granted->getId(), [
            'json' => ['title' => 'Edited'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/tasks/' . $hidden->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testItemOverrideHidesBelowViewCategory(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $member = $this->createUser('member@example.com');
        $visible = $this->createTask($member, 'Visible task');
        $hidden = $this->createTask($member, 'Hidden task');
        // Category view, but one task explicitly hidden.
        $member->setPreferences([
            'canBeImpersonated' => true,
            'impersonationAccess' => ['tasks' => 'view'],
            'impersonationItemAccess' => ['task' => [(string) $hidden->getId() => 'none']],
        ]);
        $this->entityManager->flush();

        $client = $this->impersonate($admin, 'member@example.com');

        // List drops the hidden task.
        $client->request('GET', '/tasks');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 1]);

        $client->request('GET', '/tasks/' . $hidden->getId());
        $this->assertResponseStatusCodeSame(403);

        $client->request('GET', '/tasks/' . $visible->getId());
        $this->assertResponseIsSuccessful();
    }

    public function testViewAllowsReadButBlocksWrite(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $member = $this->createImpersonableUser('member@example.com', ['tasks' => 'view']);
        $task = $this->createTask($member, 'Member task');

        $client = $this->impersonate($admin, 'member@example.com');

        $client->request('GET', '/tasks');
        $this->assertResponseIsSuccessful();

        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['title' => 'Edited by admin'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testEditAllowsWrite(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $member = $this->createImpersonableUser('member@example.com', ['tasks' => 'edit']);
        $task = $this->createTask($member, 'Member task');

        $client = $this->impersonate($admin, 'member@example.com');

        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['title' => 'Edited by admin'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Edited by admin', $this->stringField($this->body($client), 'title'));
    }

    public function testNonContentWritesAreBlocked(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        // Everything granted edit — yet self-service settings are still off
        // limits because /me/preferences isn't a content category. This is
        // what stops an impersonator from editing the very consent matrix.
        $this->createImpersonableUser('member@example.com', [
            'tasks' => 'edit',
            'boards' => 'edit',
            'pages' => 'edit',
            'comments' => 'edit',
            'notifications' => 'edit',
            'files' => 'edit',
        ]);

        $client = $this->impersonate($admin, 'member@example.com');
        $client->request('PATCH', '/me/preferences', [
            'json' => ['canBeImpersonated' => false],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testShellReadsAllowedEvenWhenEverythingNone(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        // Default matrix is all-none; opt in to impersonation only.
        $this->createImpersonableUser('member@example.com', []);

        $client = $this->impersonate($admin, 'member@example.com');
        $client->request('GET', '/api/me');
        $this->assertResponseIsSuccessful();
        // Still the impersonated member, and the app shell loads.
        $this->assertSame('member@example.com', $this->stringField($this->body($client), 'email'));
    }

    private function impersonate(User $admin, string $targetEmail): Client
    {
        $client = static::createClient();
        $client->getKernelBrowser()->followRedirects();
        $client->loginUser($admin);
        $client->request('GET', '/api/me?_switch_user=' . $targetEmail);
        $this->assertResponseIsSuccessful();
        return $client;
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

    /**
     * @param string[] $roles
     */
    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

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

    /**
     * @param array<string, string>                $access     partial category matrix
     * @param array<string, array<string, string>> $itemAccess partial per-item overrides
     */
    private function createImpersonableUser(string $email, array $access, array $itemAccess = []): User
    {
        $user = $this->createUser($email);
        $prefs = [
            'canBeImpersonated' => true,
            'impersonationAccess' => $access,
        ];
        if ([] !== $itemAccess) {
            $prefs['impersonationItemAccess'] = $itemAccess;
        }
        $user->setPreferences($prefs);
        $this->entityManager->flush();

        return $user;
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
}
