<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Engagement;
use App\Entity\Client;
use App\Entity\Board;
use App\Entity\MediaObject;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Engagements (Harvest model): admin-managed (invoices-gated) CRUD with
 * embedded categories, and assigning task-management boards to them.
 */
class EngagementTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;
    private FilesystemOperator $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        $this->storage = $container->get('media.storage');

        $this->entityManager->createQuery('DELETE FROM App\Entity\TimeEntry')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\EngagementCategory')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Engagement')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\MediaObject')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAdminCreatesEngagementWithCategories(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'EUR'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $body = $client->request('POST', '/engagements', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'name' => 'Website',
                'categories' => [
                    ['name' => 'Design', 'rateAmount' => 9000, 'position' => 0],
                    ['name' => 'Dev', 'rateAmount' => 12000, 'position' => 1],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('Website', $body['name'] ?? null);
        // Currency defaulted from the client.
        $this->assertSame('EUR', $body['currency'] ?? null);
        $categories = $body['categories'] ?? [];
        $this->assertIsArray($categories);
        $this->assertCount(2, $categories);
    }

    public function testMemberWithoutInvoiceRoleCannotCreate(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $spaceIri = '/spaces/' . $space->getId();

        $adminClient = static::createClient();
        $adminClient->loginUser($admin);
        $clientRow = $adminClient->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $adminClient->loginUser($member);
        $adminClient->request('POST', '/engagements', [
            'json' => ['space' => $spaceIri, 'client' => $clientRow['@id'], 'name' => 'Sneaky'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAssignProjectsToEngagement(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();

        $taskProject = (new Board())->setOwner($admin)->setTitle('App')->setSpace($space);
        $this->entityManager->persist($taskProject);
        $client = (new Client())->setSpace($space)->setName('Acme')->setCreatedBy($admin);
        $this->entityManager->persist($client);
        $engagement = (new Engagement())->setSpace($space)->setClient($client)->setName('Website')->setCreatedBy($admin);
        $this->entityManager->persist($engagement);
        $this->entityManager->flush();

        $httpClient = static::createClient();
        $httpClient->loginUser($admin);
        $httpClient->request('PUT', '/engagements/' . $engagement->getId() . '/boards', [
            'json' => ['boards' => ['/boards/' . $taskProject->getId()]],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Board::class)->find($taskProject->getId());
        $this->assertInstanceOf(Board::class, $reloaded);
        $this->assertSame((string) $engagement->getId(), (string) $reloaded->getEngagement()?->getId());
    }

    public function testDescriptionAndContractAttachmentRoundTrip(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();
        $media = $this->createMediaObject($admin);

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $engagement = $client->request('POST', '/engagements', [
            'json' => ['space' => $spaceIri, 'client' => $clientRow['@id'], 'name' => 'Retainer'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $engagementIri = $engagement['@id'] ?? null;
        $this->assertIsString($engagementIri);

        $client->request('PATCH', $engagementIri, [
            'json' => [
                'description' => "## Scope\n\n- Weekly retainer\n- Net-30 terms",
                'attachments' => ['/media-objects/' . $media->getId()],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'description' => "## Scope\n\n- Weekly retainer\n- Net-30 terms",
            'attachments' => [
                [
                    '@type' => 'MediaObject',
                    'kind' => 'attachment',
                    'downloadUrl' => '/media-objects/' . $media->getId() . '/download',
                ],
            ],
        ]);
    }

    public function testContractDownloadGatedToInvoiceReaders(): void
    {
        $admin = $this->createUser('admin@example.com');
        $coAdmin = $this->createUser('coadmin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $this->addAdmin($space, $coAdmin);

        // Contract file uploaded + attached by the first admin.
        $media = $this->createMediaObject($admin);
        $client = (new Client())->setSpace($space)->setName('Acme')->setCreatedBy($admin);
        $this->entityManager->persist($client);
        $engagement = (new Engagement())
            ->setSpace($space)->setClient($client)->setName('Retainer')->setCreatedBy($admin);
        $engagement->addAttachment($media);
        $this->entityManager->persist($engagement);
        $this->entityManager->flush();

        $downloadUrl = '/media-objects/' . $media->getId() . '/download';
        $httpClient = static::createClient();

        // A second space admin (not the uploader) can download it.
        $httpClient->loginUser($coAdmin);
        $httpClient->request('GET', $downloadUrl);
        $this->assertResponseIsSuccessful();

        // A plain member (no invoices grant) cannot — 404, not 403, to avoid leaking existence.
        $httpClient->loginUser($member);
        $httpClient->request('GET', $downloadUrl);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testStrangerUploadCannotBeAttached(): void
    {
        $admin = $this->createUser('admin@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSharedSpace($admin);
        $engagementIri = $this->seedEngagement($admin, $space);
        $media = $this->createMediaObject($stranger);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('PATCH', $engagementIri, [
            'json' => ['attachments' => ['/media-objects/' . $media->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testAvatarKindCannotBeAttached(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $engagementIri = $this->seedEngagement($admin, $space);
        $avatar = $this->createMediaObject($admin, MediaObject::KIND_AVATAR);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('PATCH', $engagementIri, [
            'json' => ['attachments' => ['/media-objects/' . $avatar->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testGlobalAdminCanDownloadEngagementContract(): void
    {
        // A ROLE_ADMIN who isn't a member of the space can still download an
        // engagement contract (mirrors the Engagement GET admin bypass).
        $owner = $this->createUser('owner@example.com');
        $superAdmin = $this->createUser('super@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $space = $this->createSharedSpace($owner);
        $media = $this->createMediaObject($owner);

        $client = (new Client())->setSpace($space)->setName('Acme')->setCreatedBy($owner);
        $this->entityManager->persist($client);
        $engagement = (new Engagement())
            ->setSpace($space)->setClient($client)->setName('Retainer')->setCreatedBy($owner);
        $engagement->addAttachment($media);
        $this->entityManager->persist($engagement);
        $this->entityManager->flush();

        $httpClient = static::createClient();
        $httpClient->loginUser($superAdmin);
        $httpClient->request('GET', '/media-objects/' . $media->getId() . '/download');
        $this->assertResponseIsSuccessful();
    }

    /** Creates a client + engagement for $admin in $space; returns the engagement IRI. */
    private function seedEngagement(User $admin, Space $space): string
    {
        $client = (new Client())->setSpace($space)->setName('Acme')->setCreatedBy($admin);
        $this->entityManager->persist($client);
        $engagement = (new Engagement())
            ->setSpace($space)->setClient($client)->setName('Retainer')->setCreatedBy($admin);
        $this->entityManager->persist($engagement);
        $this->entityManager->flush();

        return '/engagements/' . $engagement->getId();
    }

    private function createMediaObject(User $owner, string $kind = MediaObject::KIND_ATTACHMENT): MediaObject
    {
        $bytes = 'CONTRACT-PDF';
        $path = 'attachments/engagement-test-' . bin2hex(random_bytes(4)) . '-contract.pdf';
        $this->storage->write($path, $bytes);

        $media = (new MediaObject())
            ->setOwner($owner)
            ->setKind($kind)
            ->setVariants(['original' => $path])
            ->setOriginalName('contract.pdf')
            ->setMimeType('application/pdf')
            ->setByteSize(\strlen($bytes));
        $this->entityManager->persist($media);
        $this->entityManager->flush();

        return $media;
    }

    private function addAdmin(Space $space, User $user): void
    {
        $membership = (new SpaceMembership())->setUser($user)->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($membership);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
    }

    private function createSharedSpace(User $admin, ?User $member = null): Space
    {
        $space = (new Space())->setName('Studio')->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $adminMembership = (new SpaceMembership())->setUser($admin)->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($adminMembership);
        $this->entityManager->persist($adminMembership);
        if (null !== $member) {
            $memberMembership = (new SpaceMembership())->setUser($member)->setRole(Space::ROLE_MEMBER);
            $space->addUserMembership($memberMembership);
            $this->entityManager->persist($memberMembership);
        }
        $this->entityManager->flush();

        return $space;
    }

    /**
     * @param list<string> $roles
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
}
