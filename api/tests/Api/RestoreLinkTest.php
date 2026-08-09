<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Deletion\PurgeRunner;
use App\Deletion\SoftDeletionService;
use App\Entity\RestoreToken;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The emailed restore link, end to end.
 *
 * The load-bearing property here is that it works **without a session**: an
 * account inside its deletion grace period can't sign in, so a link that
 * required auth would be useless for exactly the case it exists for. These
 * tests deliberately use a client that never logs in.
 */
class RestoreLinkTest extends ApiTestCase
{
    private const PASSWORD = 'Password123!@#';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\RestoreToken')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceRole')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAccountDeletionEmailsALinkThatRestoresWithoutSigningIn(): void
    {
        $user = $this->createUser('leaver@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/me/delete', [
            'json' => [
                'confirmEmail' => 'leaver@example.com',
                'currentPassword' => self::PASSWORD,
                'reason' => 'not_using',
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(202);

        $token = $this->plainTokenFor('account');

        // A brand-new client with no session at all — the account can't sign
        // in, so this is the only way back.
        $anonymous = static::createClient();
        $status = $anonymous->request('GET', '/restore/' . $token)->toArray();
        $this->assertSame('ready', $status['status']);
        $this->assertSame('account', $status['targetType']);
        $this->assertSame('leaver@example.com', $status['label']);

        $anonymous->request('POST', '/restore/' . $token);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $restored = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'leaver@example.com']);
        $this->assertNotNull($restored);
        $this->assertFalse($restored->isDeleted(), 'the account should be live again');
        $this->assertContains('ROLE_USER', $restored->getRoles());
    }

    public function testASpentLinkCannotBeReused(): void
    {
        $user = $this->createUser('leaver@example.com');
        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/me/delete', [
            'json' => [
                'confirmEmail' => 'leaver@example.com',
                'currentPassword' => self::PASSWORD,
                'reason' => 'not_using',
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $token = $this->plainTokenFor('account');
        $anonymous = static::createClient();
        $anonymous->request('POST', '/restore/' . $token);
        $this->assertResponseIsSuccessful();

        // Second click: reports what happened rather than a generic failure.
        $anonymous->request('POST', '/restore/' . $token);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testUnknownTokenIsNotFound(): void
    {
        $anonymous = static::createClient();
        $anonymous->request('GET', '/restore/' . bin2hex(random_bytes(32)));

        // Flat 404 so the endpoint can't be used to enumerate live tokens.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeletedAccountCannotSignIn(): void
    {
        $user = $this->createUser('leaver@example.com');
        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/me/delete', [
            'json' => [
                'confirmEmail' => 'leaver@example.com',
                'currentPassword' => self::PASSWORD,
                'reason' => 'not_using',
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(202);

        $anonymous = static::createClient();
        $anonymous->request('POST', '/auth/login', [
            'json' => ['email' => 'leaver@example.com', 'password' => self::PASSWORD],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        // Credentials are correct; the account state is what refuses.
        $this->assertResponseStatusCodeSame(401);
    }

    public function testLinkStopsWorkingOnceThePurgeHasRun(): void
    {
        $user = $this->createUser('leaver@example.com');
        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/me/delete', [
            'json' => [
                'confirmEmail' => 'leaver@example.com',
                'currentPassword' => self::PASSWORD,
                'reason' => 'not_using',
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $token = $this->plainTokenFor('account');

        $purge = static::getContainer()->get(PurgeRunner::class);
        $purge->run(new \DateTimeImmutable('+31 days'));

        $anonymous = static::createClient();
        $anonymous->request('POST', '/restore/' . $token);
        // The token itself is swept by the purge, so this is a flat 404 rather
        // than a "gone" — either way the link can't resurrect anything.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testSpaceDeletionSchedulesRatherThanRemoving(): void
    {
        $user = $this->createUser('admin@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $body = $client->request('POST', '/spaces', [
            'json' => ['name' => 'Doomed Space'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $spaceId = $body['id'];
        $this->assertIsString($spaceId);

        $client->request('DELETE', '/spaces/' . $spaceId, [
            'json' => ['currentPassword' => self::PASSWORD],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        // Row survives, but drops out of the listing.
        $this->entityManager->clear();
        $space = $this->entityManager->getRepository(Space::class)->find($spaceId);
        $this->assertNotNull($space);
        $this->assertTrue($space->isDeleted());

        $list = $client->request('GET', '/spaces')->toArray();
        $this->assertSame(0, $list['totalItems']);

        // ...and is reachable again through the recovery listing.
        $deleted = $client->request('GET', '/spaces/deleted')->toArray();
        $this->assertCount(1, $deleted['spaces']);
    }

    /** The plaintext token isn't stored, so re-mint the hash lookup by brute mapping. */
    private function plainTokenFor(string $targetType): string
    {
        // The service hands the plaintext only to the mailer, so tests can't
        // read it back. Re-issue deterministically instead: replace the stored
        // row's hash with one we know, which exercises the same lookup path.
        $row = $this->entityManager->getRepository(RestoreToken::class)
            ->findOneBy(['targetType' => $targetType]);
        $this->assertNotNull($row, 'deletion should have minted a restore token');

        $plain = bin2hex(random_bytes(32));
        $reflection = new \ReflectionProperty(RestoreToken::class, 'tokenHash');
        $reflection->setValue($row, hash('sha256', $plain));
        $this->entityManager->flush();

        return $plain;
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
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
