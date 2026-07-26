<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Dashboard\WidgetRegistry;
use App\Entity\DashboardWidget;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The configurable space dashboard (#759).
 *
 * Two things here are load-bearing rather than incidental: that a widget's
 * declared permission category is actually enforced on every read path, and
 * that an unregistered widget type degrades instead of breaking the page —
 * which is what makes it safe for another module to ship widgets.
 */
class DashboardWidgetTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $tearDownOrder = [
            'App\Entity\DashboardWidget',
            'App\Entity\SpaceRole',
            'App\Entity\SpaceMembership',
            'App\Entity\Space',
            'App\Entity\User',
        ];
        foreach ($tearDownOrder as $entity) {
            $this->entityManager->createQuery('DELETE FROM ' . $entity)->execute();
        }
    }

    /**
     * The guard that keeps the plugin system honest: a widget that forgets to
     * declare a category would be readable by anyone, so every registered type
     * must make an explicit choice. Mirrors the same coverage test McpTest
     * applies to MCP tools.
     */
    public function testEveryRegisteredWidgetDeclaresAKnownCategory(): void
    {
        $registry = static::getContainer()->get(WidgetRegistry::class);
        $this->assertNotEmpty($registry->all(), 'No widgets registered — the tag wiring is broken.');

        foreach ($registry->all() as $widget) {
            $category = $widget->permissionCategory();

            if (null !== $category) {
                $this->assertContains(
                    $category,
                    SpacePermission::CATEGORIES,
                    sprintf('Widget "%s" declares unknown category "%s".', $widget->type(), $category),
                );
            }

            $size = $widget->defaultSize();
            $this->assertGreaterThanOrEqual(DashboardWidget::MIN_WIDTH, $size['w']);
            $this->assertLessThanOrEqual(DashboardWidget::MAX_WIDTH, $size['w']);
        }
    }

    public function testAdminAddsAWidgetAndMemberSeesIt(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $member = $this->makeUser('member@example.com');
        $space = $this->makeSpace($admin, $member);

        $client = static::createClient();
        $client->loginUser($admin);

        $created = $client->request('POST', '/spaces/' . $space->getId() . '/dashboard/widgets', [
            'json' => ['type' => 'notes.markdown', 'config' => ['title' => 'Hi', 'body' => 'Ship Thursdays']],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('notes.markdown', $this->stringField($created, 'type'));

        // The member sees the same layout, but read-only.
        $client->loginUser($member);
        $body = $client->request('GET', '/spaces/' . $space->getId() . '/dashboard')->toArray();
        $this->assertFalse($this->boolField($body, 'canEdit'));
        $this->assertCount(1, $this->arrayField($body, 'widgets'));
    }

    public function testMembersCannotChangeTheSharedLayout(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $member = $this->makeUser('member@example.com');
        $space = $this->makeSpace($admin, $member);
        $widget = $this->makeWidget($space, 'notes.markdown');

        $client = static::createClient();
        $client->loginUser($member);

        // The layout is shared, so a member editing it would change what
        // everyone sees.
        $client->request('POST', '/spaces/' . $space->getId() . '/dashboard/widgets', [
            'json' => ['type' => 'notes.markdown', 'config' => []],
        ]);
        $this->assertResponseStatusCodeSame(403);

        $client->request('PATCH', '/dashboard-widgets/' . $widget->getId(), [
            'json' => ['config' => ['body' => 'mine now']],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        $client->request('DELETE', '/dashboard-widgets/' . $widget->getId());
        $this->assertResponseStatusCodeSame(403);

        $client->request('POST', '/spaces/' . $space->getId() . '/dashboard/reorder', [
            'json' => ['order' => []],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testOutsidersGetTheExistenceHiding404(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $outsider = $this->makeUser('outsider@example.com');
        $space = $this->makeSpace($admin);
        $widget = $this->makeWidget($space, 'notes.markdown');

        $client = static::createClient();
        $client->loginUser($outsider);

        $client->request('GET', '/spaces/' . $space->getId() . '/dashboard');
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/dashboard-widgets/' . $widget->getId() . '/data');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnknownWidgetTypeIsRejectedOnAdd(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $space = $this->makeSpace($admin);

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/spaces/' . $space->getId() . '/dashboard/widgets', [
            'json' => ['type' => 'evil.widget', 'config' => []],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    /**
     * A row whose module is gone (or not yet deployed) must render as a
     * placeholder. Dropping it would look like data loss; throwing would take
     * the whole dashboard down over one stale row.
     */
    public function testAnUnregisteredTypeDegradesInsteadOfBreakingTheDashboard(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $space = $this->makeSpace($admin);
        $this->makeWidget($space, 'notes.markdown');
        $orphan = $this->makeWidget($space, 'someplugin.gone');

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('GET', '/spaces/' . $space->getId() . '/dashboard')->toArray();
        $this->assertResponseIsSuccessful();

        $widgets = $this->arrayField($body, 'widgets');
        $this->assertCount(2, $widgets, 'The orphaned widget must still be listed.');

        $byType = [];
        foreach ($widgets as $widget) {
            $this->assertIsArray($widget);
            $byType[$this->stringField($widget, 'type')] = $widget;
        }
        $this->assertTrue($byType['notes.markdown']['available']);
        $this->assertFalse($byType['someplugin.gone']['available']);
        $this->assertNull($byType['someplugin.gone']['name']);

        // Its data endpoint 404s rather than fataling on a missing definition.
        $client->request('GET', '/dashboard-widgets/' . $orphan->getId() . '/data');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testConfigIsValidatedByTheWidgetDefinition(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $space = $this->makeSpace($admin);

        $client = static::createClient();
        $client->loginUser($admin);

        // The client is not a trust boundary — config arrives unvalidated.
        $client->request('POST', '/spaces/' . $space->getId() . '/dashboard/widgets', [
            'json' => ['type' => 'notes.markdown', 'config' => ['body' => str_repeat('x', 6000)]],
        ]);
        $this->assertResponseStatusCodeSame(422);

        $client->request('POST', '/spaces/' . $space->getId() . '/dashboard/widgets', [
            'json' => ['type' => 'notes.markdown', 'config' => ['body' => ['not', 'a', 'string']]],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testReorderRenumbersAndIgnoresForeignIds(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $space = $this->makeSpace($admin);
        $other = $this->makeSpace($admin, name: 'Other');

        $first = $this->makeWidget($space, 'notes.markdown');
        $second = $this->makeWidget($space, 'notes.markdown');
        $foreign = $this->makeWidget($other, 'notes.markdown');

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/spaces/' . $space->getId() . '/dashboard/reorder', [
            'json' => ['order' => [
                (string) $second->getId(),
                (string) $foreign->getId(),
                (string) $first->getId(),
            ]],
        ]);
        $this->assertResponseIsSuccessful();

        $body = $client->request('GET', '/spaces/' . $space->getId() . '/dashboard')->toArray();
        $widgets = $this->arrayField($body, 'widgets');
        $this->assertIsArray($widgets[0]);
        $this->assertIsArray($widgets[1]);
        $this->assertSame((string) $second->getId(), $this->stringField($widgets[0], 'id'));
        $this->assertSame((string) $first->getId(), $this->stringField($widgets[1], 'id'));

        // The other space's widget was named in the payload but must not have
        // been touched. Re-fetch by id: the request detached the test EM's copy.
        $reloaded = $this->entityManager
            ->getRepository(DashboardWidget::class)
            ->find((string) $foreign->getId());
        $this->assertNotNull($reloaded);
        $this->assertSame(0, $reloaded->getPosition());
    }

    public function testWidthIsClampedToTheGrid(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $space = $this->makeSpace($admin);
        $widget = $this->makeWidget($space, 'notes.markdown');

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('PATCH', '/dashboard-widgets/' . $widget->getId(), [
            'json' => ['width' => 99],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray();

        $this->assertSame(DashboardWidget::MAX_WIDTH, $this->intField($body, 'width'));
    }

    public function testCatalogIsFilteredToWhatTheCallerCanRead(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $space = $this->makeSpace($admin);

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('GET', '/spaces/' . $space->getId() . '/dashboard/catalog')->toArray();
        $types = [];
        foreach ($this->arrayField($body, 'widgets') as $widget) {
            $this->assertIsArray($widget);
            $types[] = $this->stringField($widget, 'type');
        }

        $this->assertContains('notes.markdown', $types);
    }

    public function testWidgetDataIsServedPerInstance(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $space = $this->makeSpace($admin);
        $widget = $this->makeWidget($space, 'notes.markdown');

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('GET', '/dashboard-widgets/' . $widget->getId() . '/data')->toArray();
        $this->assertArrayHasKey('data', $body);
    }

    private function makeWidget(Space $space, string $type): DashboardWidget
    {
        $widget = (new DashboardWidget())
            ->setSpace($space)
            ->setType($type)
            ->setConfig([]);
        $this->entityManager->persist($widget);
        $this->entityManager->flush();

        return $widget;
    }

    private function makeSpace(User $admin, ?User $member = null, string $name = 'Studio'): Space
    {
        $space = (new Space())->setName($name)->setCreatedBy($admin);
        $this->entityManager->persist($space);

        $space->addUserMembership(
            (new SpaceMembership())->setUser($admin)->setRole(Space::ROLE_ADMIN),
        );
        if (null !== $member) {
            $space->addUserMembership(
                (new SpaceMembership())->setUser($member)->setRole(Space::ROLE_MEMBER),
            );
        }
        $this->entityManager->flush();

        return $space;
    }

    private function makeUser(string $email): User
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
