<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\GlobalCustomFieldDefinition;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Instance-wide, admin-managed global custom fields (#global-custom-fields).
 * Writes are ROLE_ADMIN; reads are open to any authenticated user so the
 * fields render in the per-project picker.
 */
class GlobalCustomFieldDefinitionTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->createQuery('DELETE FROM App\Entity\CustomFieldValue')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\GlobalCustomFieldDefinition')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testNonAdminCannotCreate(): void
    {
        $user = $this->makeUser('plain@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/global_custom_field_definitions', [
            'json' => ['name' => 'Effort', 'kind' => 'numeric', 'subtype' => 'int', 'config' => []],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAnyAuthenticatedUserCanList(): void
    {
        $this->seedGlobal('Effort', 'numeric', 'int');
        $user = $this->makeUser('plain@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('GET', '/global_custom_field_definitions', [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testAdminCreatesAndSerializes(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/global_custom_field_definitions', [
            'json' => [
                'name' => 'Cost',
                'kind' => 'numeric',
                'subtype' => 'money',
                'config' => ['currency' => 'USD'],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = $this->body($client);
        $this->assertSame('Cost', $this->stringField($body, 'name'));
        $this->assertSame('numeric', $this->stringField($body, 'kind'));
        $this->assertSame('money', $this->stringField($body, 'subtype'));
    }

    public function testReferenceKindRejected(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/global_custom_field_definitions', [
            'json' => [
                'name' => 'Owner',
                'kind' => 'reference',
                'subtype' => 'user',
                'config' => [],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        // Global fields have no space to scope reference targets against.
        $this->assertResponseStatusCodeSame(422);
    }

    public function testSelectAcceptsBreakdownFooterButNumericRejectsIt(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $client = static::createClient();
        $client->loginUser($admin);

        // A select field may declare the per-option `breakdown` footer.
        $client->request('POST', '/global_custom_field_definitions', [
            'json' => [
                'name' => 'Status',
                'kind' => 'select',
                'subtype' => 'single',
                'config' => ['multi' => false, 'options' => [
                    ['key' => 'backlog', 'label' => 'Backlog'],
                    ['key' => 'done', 'label' => 'Done'],
                ]],
                'footer' => ['kind' => 'breakdown'],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Breakdown is meaningless on a numeric field — the strategy doesn't
        // advertise it, so the footer validator rejects it.
        $client->request('POST', '/global_custom_field_definitions', [
            'json' => [
                'name' => 'Effort',
                'kind' => 'numeric',
                'subtype' => 'int',
                'config' => [],
                'footer' => ['kind' => 'breakdown'],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testDuplicateNameRejected(): void
    {
        $this->seedGlobal('Effort', 'numeric', 'int');
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/global_custom_field_definitions', [
            'json' => ['name' => 'Effort', 'kind' => 'numeric', 'subtype' => 'int', 'config' => []],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testAdminCanPatchAndNonAdminCannot(): void
    {
        $field = $this->seedGlobal('Effort', 'numeric', 'int');
        $iri = '/global_custom_field_definitions/' . $field->getId();

        $client = static::createClient();
        $client->loginUser($this->makeUser('plain@example.com'));
        $client->request('PATCH', $iri, [
            'json' => ['name' => 'Renamed'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        $client->loginUser($this->makeUser('admin@example.com', ['ROLE_ADMIN']));
        $client->request('PATCH', $iri, [
            'json' => ['name' => 'Renamed'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testAdminCanDelete(): void
    {
        $field = $this->seedGlobal('Effort', 'numeric', 'int');
        $client = static::createClient();
        $client->loginUser($this->makeUser('admin@example.com', ['ROLE_ADMIN']));
        $client->request('DELETE', '/global_custom_field_definitions/' . $field->getId());
        $this->assertResponseStatusCodeSame(204);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function body(\ApiPlatform\Symfony\Bundle\Test\Client $client): array
    {
        $response = $client->getResponse();
        self::assertNotNull($response);
        return $response->toArray(false);
    }

    private function seedGlobal(string $name, string $kind, string $subtype): GlobalCustomFieldDefinition
    {
        $field = new GlobalCustomFieldDefinition();
        $field->setName($name);
        $field->setKind($kind);
        $field->setSubtype($subtype);
        $this->em->persist($field);
        $this->em->flush();
        return $field;
    }

    /**
     * @param string[] $roles
     */
    private function makeUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
}
