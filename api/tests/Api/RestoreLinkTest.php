<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Deletion\PurgeRunner;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;
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
    use MailerAssertionsTrait;

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

        $token = $this->plainTokenFromEmail();

        // A brand-new client with no session at all — the account can't sign
        // in, so this is the only way back.
        $anonymous = static::createClient();
        $status = $anonymous->request('GET', '/restore/' . $token)->toArray();
        $this->assertSame('ready', $status['status']);
        $this->assertSame('account', $status['targetType']);
        $this->assertSame('leaver@example.com', $status['label']);

        $anonymous->request('POST', '/restore/' . $token);
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $restored = $this->em()->getRepository(User::class)
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

        $token = $this->plainTokenFromEmail();
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
        $token = $this->plainTokenFromEmail();

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
        $this->em()->clear();
        $space = $this->em()->getRepository(Space::class)->find($spaceId);
        $this->assertNotNull($space);
        $this->assertTrue($space->isDeleted());

        $list = $client->request('GET', '/spaces')->toArray();
        $this->assertSame(0, $list['totalItems']);

        // ...and is reachable again through the recovery listing.
        $deleted = $client->request('GET', '/spaces/deleted')->toArray();
        $deletedSpaces = $deleted['spaces'];
        $this->assertIsArray($deletedSpaces);
        $this->assertCount(1, $deletedSpaces);
    }

    public function testSpaceCanBeRestoredInApp(): void
    {
        $user = $this->createUser('admin@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $body = $client->request('POST', '/spaces', [
            'json' => ['name' => 'Second Thoughts'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $spaceId = $body['id'];
        $this->assertIsString($spaceId);

        $client->request('DELETE', '/spaces/' . $spaceId, [
            'json' => ['currentPassword' => self::PASSWORD],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        // The in-app path, for an admin who's already signed in and doesn't
        // want to go hunting through their inbox.
        $client->request('POST', '/spaces/' . $spaceId . '/restore', [
            'json' => ['currentPassword' => self::PASSWORD],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $list = $client->request('GET', '/spaces')->toArray();
        $this->assertSame(1, $list['totalItems'], 'the space should be listed again');
    }

    public function testRestoringASpaceThatIsNotDeletedIsRejected(): void
    {
        $user = $this->createUser('admin@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $body = $client->request('POST', '/spaces', [
            'json' => ['name' => 'Perfectly Fine'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $spaceId = $body['id'];
        $this->assertIsString($spaceId);

        $client->request('POST', '/spaces/' . $spaceId . '/restore', [
            'json' => ['currentPassword' => self::PASSWORD],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);
    }

    /**
     * Pull the token out of the email we actually sent.
     *
     * The plaintext is deliberately never stored — only its hash is — so
     * reading it back from the message is both the only way and the more
     * honest test: it proves the link that lands in someone's inbox is the one
     * that works, rather than a token the test minted for itself.
     */
    private function plainTokenFromEmail(): string
    {
        $message = $this->getMailerMessage();
        // Read the rendered bodies rather than toString(): the From header is
        // applied by the mailer envelope at send time, so serializing the
        // collected message throws.
        $this->assertInstanceOf(Email::class, $message, 'scheduling a deletion should send a notice email');

        // A From address is required or the transport rejects the message —
        // and because the collector runs *before* the transport, an email with
        // no sender still shows up in every count assertion while never
        // actually being delivered. This is the only thing that catches it.
        $this->assertNotEmpty(
            $message->getFrom(),
            'the notice must carry a From address or it never leaves the queue',
        );

        $body = (string) $message->getHtmlBody() . (string) $message->getTextBody();

        $matched = preg_match('#/restore/([0-9a-f]{64})#', $body, $matches);
        $this->assertSame(1, $matched, 'the email should carry a restore link');

        return $matches[1];
    }

    /** Re-acquire the EM: creating a client reboots the kernel. */
    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);

        return $em;
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
