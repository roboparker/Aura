<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\ApiToken;
use App\Entity\Board;
use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\SpaceRole;
use App\Entity\Task;
use App\Entity\User;
use App\Security\AgentSignInDeniedException;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use App\Security\UserChecker;
use App\Service\AgentProvisioner;
use App\Service\PersonalOrganizationProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * AI agents, step 1 (#827): an agent exists, holds permissions, and holds a
 * token — and is suppressed everywhere being a `User` would otherwise imply
 * something it must not have.
 */
class AgentTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        foreach (
            [
                'Notification', 'Comment', 'ApiToken', 'Task', 'SpaceRole', 'Board',
                'Space', 'OrganizationMembership', 'Organization', 'User',
            ] as $entity
        ) {
            $this->entityManager->createQuery("DELETE FROM App\\Entity\\$entity")->execute();
        }
    }

    // --- Provisioning -----------------------------------------------------

    public function testAdminProvisionsAgentAndSeesPlaintextOnce(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $role = $this->seedRole($space, ['tasks' => ['read' => true]]);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/spaces/' . $space->getId() . '/agents', [
            'json' => ['name' => 'Support bot', 'roles' => ['/space_roles/' . $role->getId()]],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $this->body($client);
        $this->assertSame('Support bot', $this->stringField($body, 'name'));
        $this->assertStringStartsWith(ApiToken::PLAINTEXT_PREFIX, $this->stringField($body, 'plainToken'));
        $this->assertCount(1, $this->arrayField($body, 'roles'));

        // The row is an agent, and the credential it got is scoped to the space.
        $agent = $this->entityManager->getRepository(User::class)->find($this->stringField($body, 'id'));
        $this->assertInstanceOf(User::class, $agent);
        $this->assertTrue($agent->isAgent());
        $token = $this->entityManager->getRepository(ApiToken::class)->findOneBy(['user' => $agent]);
        $this->assertInstanceOf(ApiToken::class, $token);
        $this->assertTrue($token->isScoped());
        $this->assertCount(1, $token->getRoles());

        // Listing it back never re-reveals the plaintext.
        $client->request('GET', '/spaces/' . $space->getId() . '/agents');
        $listed = $this->arrayField($this->body($client), 'agents');
        $this->assertCount(1, $listed);
        $first = $listed[0];
        $this->assertIsArray($first);
        $this->assertArrayNotHasKey('plainToken', $first);
    }

    public function testAgentGetsAMembershipButNoOrganizationSeat(): void
    {
        $admin = $this->createUser('admin@example.com');
        $organization = $this->createOrganization($admin);
        $space = $this->createSpace($admin, organization: $organization);
        $seatsBefore = $organization->seatCount();

        $this->provisionAgent($space, 'Helper');

        // It is a member of the space (that's how it gets access at all)...
        $this->assertCount(2, $space->getUserMemberships());
        // ...but it did not join the organization, and cannot be billed for.
        $this->assertSame($seatsBefore, $organization->seatCount());
    }

    public function testAgentOnAnOrganizationRosterStillDoesNotCountAsASeat(): void
    {
        // Belt to the braces above: even if some future path puts an agent on
        // an org roster with a billable role, the seat count must not move —
        // this number is pushed to Stripe as the subscription quantity.
        $owner = $this->createUser('owner@example.com');
        $organization = $this->createOrganization($owner);
        $space = $this->createSpace($owner, organization: $organization);
        $agent = $this->provisionAgent($space, 'Helper');

        $organization->addMember($agent, Organization::ROLE_MEMBER);
        $this->entityManager->flush();

        $this->assertSame(1, $organization->seatCount());
    }

    public function testAgentCannotBeAddedToAPrivateSpace(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $space->setVisibility(Space::VISIBILITY_PRIVATE);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/spaces/' . $space->getId() . '/agents', [
            'json' => ['name' => 'Nope', 'roles' => []],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testProvisioningRejectsBadInput(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $otherSpace = $this->createSpace($admin, name: 'Other');
        $foreignRole = $this->seedRole($otherSpace, ['tasks' => ['read' => true]]);

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/spaces/' . $space->getId() . '/agents', [
            'json' => ['name' => '  ', 'roles' => []],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        // A role from a space the caller also admins is still not this space's.
        $client->request('POST', '/spaces/' . $space->getId() . '/agents', [
            'json' => ['name' => 'Bot', 'roles' => ['/space_roles/' . $foreignRole->getId()]],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        $client->request('POST', '/spaces/' . $space->getId() . '/agents', [
            'json' => ['name' => 'Bot', 'roles' => 'not-an-array'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        $client->request('POST', '/spaces/' . $space->getId() . '/agents', [
            'json' => ['name' => 'Bot', 'roles' => ['/space_roles/not-a-uuid']],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        $client->request('POST', '/spaces/' . $space->getId() . '/agents', [
            'json' => ['name' => 'Bot', 'roles' => [42]],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testUnknownSpaceAndUnauthenticatedCallersAreRefused(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $agent = $this->provisionAgent($space, 'Helper');

        $anonymous = static::createClient();
        $anonymous->request('GET', '/spaces/' . $space->getId() . '/agents');
        $this->assertResponseStatusCodeSame(401);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('GET', '/spaces/not-a-uuid/agents');
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/spaces/' . $agent->getId() . '/agents');
        $this->assertResponseStatusCodeSame(404);

        // An unknown agent id under a space the caller does administer.
        $client->request('PATCH', '/spaces/' . $space->getId() . '/agents/not-a-uuid', [
            'json' => ['roles' => []],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);

        // A rename still has to be a name.
        $client->request('PATCH', '/spaces/' . $space->getId() . '/agents/' . $agent->getId(), [
            'json' => ['name' => ''],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testAnUnnameableAgentStillGetsAUsableIdentity(): void
    {
        // A name that slugifies to nothing must not produce an empty email
        // local part or an empty givenName — both would break invariants the
        // rest of the app assumes about a User row.
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $agent = $this->provisionAgent($space, '   ');

        $this->assertStringEndsWith('@' . User::AGENT_EMAIL_DOMAIN, $agent->getEmail());
        $this->assertStringStartsWith('agent-', $agent->getEmail());
        $this->assertNotSame('', $agent->getGivenName());
        $this->assertNotSame('', $agent->getFamilyName());
    }

    public function testProvisioningIsGatedAndHidesTheSpaceFromOutsiders(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSpace($admin, $member);

        $client = static::createClient();

        // A plain member holds no `api_keys` grant → 403.
        $client->loginUser($member);
        $client->request('POST', '/spaces/' . $space->getId() . '/agents', [
            'json' => ['name' => 'Sneaky', 'roles' => []],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        // A non-member gets 404 — existence-hiding, as everywhere else.
        $client->loginUser($stranger);
        $client->request('GET', '/spaces/' . $space->getId() . '/agents');
        $this->assertResponseStatusCodeSame(404);
    }

    // --- Role changes + removal ------------------------------------------

    public function testChangingRolesRewritesBothTheMembershipAndTheToken(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $readRole = $this->seedRole($space, ['tasks' => ['read' => true]]);
        $writeRole = $this->seedRole($space, ['tasks' => ['read' => true, 'update' => true]]);
        $agent = $this->provisionAgent($space, 'Helper', [$readRole]);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('PATCH', '/spaces/' . $space->getId() . '/agents/' . $agent->getId(), [
            'json' => ['name' => 'Renamed', 'roles' => ['/space_roles/' . $writeRole->getId()]],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('Renamed', $this->stringField($this->body($client), 'name'));

        $this->entityManager->clear();
        $token = $this->entityManager->getRepository(ApiToken::class)
            ->findOneBy(['user' => $agent->getId()]);
        $this->assertInstanceOf(ApiToken::class, $token);
        $this->assertCount(1, $token->getRoles());
        $granted = $token->getRoles()->first();
        $this->assertInstanceOf(SpaceRole::class, $granted);
        // The token's ceiling moved with the membership's, not behind it.
        $this->assertSame((string) $writeRole->getId(), (string) $granted->getId());
    }

    public function testRemovingAnAgentRevokesItsCredential(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $agent = $this->provisionAgent($space, 'Helper');
        $agentId = (string) $agent->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('DELETE', '/spaces/' . $space->getId() . '/agents/' . $agentId);
        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $this->assertNull($this->entityManager->getRepository(User::class)->find($agentId));
        $this->assertCount(0, $this->entityManager->getRepository(ApiToken::class)->findAll());
        // Only the admin's own membership survives.
        $this->assertCount(1, $this->entityManager->getRepository(SpaceMembership::class)->findAll());
    }

    public function testAgentRoutesRefuseToActOnAHumanMember(): void
    {
        // Otherwise these endpoints would be a way to remove a colleague while
        // skipping every invariant SpaceMemberController enforces.
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSpace($admin, $member);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('DELETE', '/spaces/' . $space->getId() . '/agents/' . $member->getId());
        $this->assertResponseStatusCodeSame(404);

        $client->request('PATCH', '/spaces/' . $space->getId() . '/agents/' . $member->getId(), [
            'json' => ['roles' => []],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    // --- What the flag suppresses ----------------------------------------

    public function testAgentHoldsNoRoleUserSoEveryResourceFailsClosed(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $board = $this->createBoard($admin, $space);
        $task = $this->createTask($admin, $board);
        $role = $this->seedRole($space, ['tasks' => ['read' => true]]);
        $agent = $this->provisionAgent($space, 'Helper', [$role]);

        $this->assertSame([User::ROLE_AGENT], $agent->getRoles());
        $this->assertNotContains('ROLE_USER', $agent->getRoles());

        // Its bearer authenticates — the credential is real — but reaches
        // nothing, because ROLE_USER gates every resource. Each surface an
        // agent should reach has to be opened deliberately in a later step.
        $plain = $this->plainTokenFor($space, $agent);
        $client = static::createClient();
        $client->request('GET', '/tasks/' . $task->getId(), [
            'headers' => ['Authorization' => 'Bearer ' . $plain],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAgentPermissionsAreStillLiveForTheResolver(): void
    {
        // Withholding ROLE_USER must not make the configured roles decorative:
        // SpacePermissionResolver never consults getRoles(), so the envelope an
        // admin sets is what the later autonomy steps narrow against.
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $role = $this->seedRole($space, ['tasks' => ['read' => true]]);
        $agent = $this->provisionAgent($space, 'Helper', [$role]);

        $resolver = static::getContainer()->get(SpacePermissionResolver::class);
        $this->assertTrue($space->hasMember($agent));
        $this->assertTrue($resolver->can($agent, $space, SpacePermission::TASKS, SpacePermission::READ));
        $this->assertFalse($resolver->can($agent, $space, SpacePermission::TASKS, SpacePermission::DELETE));
    }

    public function testAgentCannotSignIn(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $agent = $this->provisionAgent($space, 'Helper');

        // No password is ever set, so there is nothing that could verify.
        $this->assertSame('', $agent->getPassword());

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => ['email' => $agent->getEmail(), 'password' => 'Password123!@#'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testUserCheckerRefusesAnAgentEvenWithoutAPassword(): void
    {
        // The absence of a password already stops the form login. This closes
        // the paths that never check one — SSO identity linking above all —
        // where an agent's synthetic address could otherwise be claimed.
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $agent = $this->provisionAgent($space, 'Helper');

        $checker = new UserChecker($this->entityManager);
        $checker->checkPreAuth($agent);

        try {
            $checker->checkPostAuth($agent);
            $this->fail('An agent must not pass the post-authentication check.');
        } catch (AgentSignInDeniedException $e) {
            $this->assertSame('This account cannot sign in.', $e->getMessageKey());
        }
    }

    public function testAgentIsRefusedAPersonalOrganization(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $agent = $this->provisionAgent($space, 'Helper');

        $provisioner = static::getContainer()->get(PersonalOrganizationProvisioner::class);
        $this->expectException(\LogicException::class);
        $provisioner->provision($agent);
    }

    public function testAgentIsNotMentionable(): void
    {
        $author = $this->createUser('author@example.com');
        $space = $this->createSpace($author);
        $board = $this->createBoard($author, $space);
        $task = $this->createTask($author, $board);
        $agent = $this->provisionAgent($space, 'Helper');
        $localPart = strstr($agent->getEmail(), '@', true);
        $this->assertIsString($localPart);

        $client = static::createClient();
        $client->loginUser($author);
        $client->request('POST', '/comments', [
            'json' => [
                'task' => '/tasks/' . $task->getId(),
                'body' => 'Hey @' . $localPart . ' can you look at this?',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // v1 agents are chat-only: a mention must not resolve to one, or the
        // author is told a message was delivered to something that will act.
        $notifications = $this->entityManager->getRepository(Notification::class)
            ->findBy(['recipient' => $agent]);
        $this->assertCount(0, $notifications);
    }

    public function testAgentIsNotOfferedAsABoardMember(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $board = $this->createBoard($admin, $space);
        $agent = $this->provisionAgent($space, 'Helper');

        // Access is unchanged — the agent is a member of the space...
        $this->assertArrayHasKey((string) $agent->getId(), $board->getEffectiveMembers());
        // ...but the serialized list that feeds member chips and the assignee
        // picker is people only.
        $ids = array_map(static fn (User $u) => (string) $u->getId(), $board->getMembers());
        $this->assertNotContains((string) $agent->getId(), $ids);
        $this->assertContains((string) $admin->getId(), $ids);
    }

    public function testAgentIsFlaggedOnSerializedUserChips(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSpace($admin);
        $this->provisionAgent($space, 'Helper');

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('GET', '/spaces/' . $space->getId());
        $this->assertResponseIsSuccessful();

        $flags = [];
        foreach ($this->arrayField($this->body($client), 'userMemberships') as $membership) {
            $this->assertIsArray($membership);
            $user = $membership['user'] ?? null;
            $this->assertIsArray($user);
            $flags[] = $user['isAgent'] ?? null;
        }
        // Both rows carry the flag, so a client can tell them apart.
        $this->assertContains(true, $flags);
        $this->assertContains(false, $flags);
    }

    // --- Helpers ----------------------------------------------------------

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
     * @param list<SpaceRole> $roles
     */
    private function provisionAgent(Space $space, string $name, array $roles = []): User
    {
        $provisioner = static::getContainer()->get(AgentProvisioner::class);
        $result = $provisioner->provision($space, $name, $roles);
        $this->entityManager->flush();

        return $result['agent'];
    }

    /**
     * Re-mint a bearer for an existing agent. The provisioner's plaintext is
     * only returned at creation, and tests that need to call the API as an
     * agent need one they know.
     */
    private function plainTokenFor(Space $space, User $agent): string
    {
        $plain = ApiToken::PLAINTEXT_PREFIX . 'testsecret_' . bin2hex(random_bytes(8));
        $token = (new ApiToken())
            ->setUser($agent)
            ->setSpace($space)
            ->setName('Test')
            ->setTokenHash(hash('sha256', $plain));
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $plain;
    }

    /**
     * @param array<string, array<string, bool>> $permissions
     */
    private function seedRole(Space $space, array $permissions): SpaceRole
    {
        $role = (new SpaceRole())
            ->setSpace($space)
            ->setName('Role ' . bin2hex(random_bytes(4)))
            ->setPermissions($permissions);
        $this->entityManager->persist($role);
        $this->entityManager->flush();

        return $role;
    }

    private function createOrganization(User $owner): Organization
    {
        $organization = (new Organization())
            ->setName('Acme')
            ->setSlug('o-acme-' . bin2hex(random_bytes(4)))
            ->setCreatedBy($owner);
        $organization->addMember($owner, Organization::ROLE_OWNER);
        $this->entityManager->persist($organization);
        foreach ($organization->getMemberships() as $membership) {
            $this->entityManager->persist($membership);
        }
        $this->entityManager->flush();

        return $organization;
    }

    private function createSpace(
        User $admin,
        ?User $member = null,
        string $name = 'Team',
        ?Organization $organization = null,
    ): Space {
        $space = (new Space())->setName($name)->setCreatedBy($admin);
        if (null !== $organization) {
            $space->setOrganization($organization);
        }
        $this->entityManager->persist($space);
        $adminMembership = (new SpaceMembership())->setUser($admin)->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($adminMembership);
        $this->entityManager->persist($adminMembership);
        if (null !== $member) {
            $m = (new SpaceMembership())->setUser($member)->setRole(Space::ROLE_MEMBER);
            $space->addUserMembership($m);
            $this->entityManager->persist($m);
        }
        $this->entityManager->flush();

        return $space;
    }

    private function createBoard(User $owner, Space $space): Board
    {
        $board = (new Board())->setOwner($owner)->setTitle('Backend')->setSpace($space);
        $this->entityManager->persist($board);
        $this->entityManager->flush();

        return $board;
    }

    private function createTask(User $owner, Board $board): Task
    {
        $task = (new Task())->setOwner($owner)->setBoard($board)->setTitle('Task');
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    private function createUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
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
}
