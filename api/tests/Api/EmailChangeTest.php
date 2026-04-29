<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\EmailChangeRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class EmailChangeTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->entityManager->createQuery('DELETE FROM App\Entity\EmailChangeRequest')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    // --- Request ---

    public function testRequestRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('POST', '/auth/request-email-change', [
            'json' => ['newEmail' => 'new@example.com'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testRequestSendsConfirmEmail(): void
    {
        $user = $this->createTestUser('old@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/auth/request-email-change', [
            'json' => ['newEmail' => 'new@example.com'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage(0);
        $this->assertEmailHeaderSame($email, 'To', 'new@example.com');
        $this->assertEmailTextBodyContains($email, '/confirm-email-change?token=');

        // The user's stored email is unchanged until they confirm.
        $refreshed = $this->reloadUser('old@example.com');
        $this->assertSame('old@example.com', $refreshed->getEmail());
    }

    public function testRequestRejectsInvalidEmail(): void
    {
        $user = $this->createTestUser('old@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/auth/request-email-change', [
            'json' => ['newEmail' => 'not-an-email'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertEmailCount(0);
    }

    public function testRequestRejectsSameEmail(): void
    {
        $user = $this->createTestUser('old@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/auth/request-email-change', [
            'json' => ['newEmail' => 'old@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testRequestSilentlyNoOpsForTakenEmail(): void
    {
        $this->createTestUser('taken@example.com');
        $user = $this->createTestUser('old@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/auth/request-email-change', [
            'json' => ['newEmail' => 'taken@example.com'],
        ]);

        // Returns 200 to avoid leaking which addresses are registered,
        // but no confirmation email is actually sent.
        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(0);
    }

    public function testRequestCancelsPriorPending(): void
    {
        $user = $this->createTestUser('old@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/auth/request-email-change', [
            'json' => ['newEmail' => 'first@example.com'],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('POST', '/auth/request-email-change', [
            'json' => ['newEmail' => 'second@example.com'],
        ]);
        $this->assertResponseIsSuccessful();

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $requests = $em->getRepository(EmailChangeRequest::class)->findBy(
            ['cancelledAt' => null, 'confirmedAt' => null],
        );
        $this->assertCount(1, $requests);
        $this->assertSame('second@example.com', $requests[0]->getNewEmail());
    }

    // --- Confirm ---

    public function testConfirmFlipsEmailAndNotifiesOldAddress(): void
    {
        $user = $this->createTestUser('old@example.com');
        [, $confirmToken] = $this->seedRequest($user, 'new@example.com');

        $client = static::createClient();
        $client->request('POST', '/auth/confirm-email-change', [
            'json' => ['token' => $confirmToken],
        ]);

        $this->assertResponseIsSuccessful();
        $refreshed = $this->reloadUser('new@example.com');
        $this->assertSame('new@example.com', $refreshed->getEmail());

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage(0);
        $this->assertEmailHeaderSame($email, 'To', 'old@example.com');
        $this->assertEmailTextBodyContains($email, '/revert-email-change?token=');
        $this->assertEmailTextBodyContains($email, '/forgot-password');
    }

    public function testConfirmRejectsBadToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/auth/confirm-email-change', [
            'json' => ['token' => 'not-a-real-token'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testConfirmRejectsExpiredToken(): void
    {
        $user = $this->createTestUser('old@example.com');
        [, $confirmToken] = $this->seedRequest(
            $user,
            'new@example.com',
            new \DateTimeImmutable('-5 minutes'),
        );

        $client = static::createClient();
        $client->request('POST', '/auth/confirm-email-change', [
            'json' => ['token' => $confirmToken],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testConfirmRejectsAlreadyUsedToken(): void
    {
        $user = $this->createTestUser('old@example.com');
        [, $confirmToken] = $this->seedRequest($user, 'new@example.com');

        $client = static::createClient();
        $client->request('POST', '/auth/confirm-email-change', [
            'json' => ['token' => $confirmToken],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('POST', '/auth/confirm-email-change', [
            'json' => ['token' => $confirmToken],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testConfirmRejectsWhenAddressBecameUnavailable(): void
    {
        $user = $this->createTestUser('old@example.com');
        [, $confirmToken] = $this->seedRequest($user, 'new@example.com');

        // Someone else grabs the address before this user confirms.
        $this->createTestUser('new@example.com');

        $client = static::createClient();
        $client->request('POST', '/auth/confirm-email-change', [
            'json' => ['token' => $confirmToken],
        ]);
        $this->assertResponseStatusCodeSame(409);
    }

    // --- Revert ---

    public function testRevertRollsEmailBack(): void
    {
        $user = $this->createTestUser('old@example.com');
        [, $confirmToken] = $this->seedRequest($user, 'new@example.com');

        $client = static::createClient();
        $client->request('POST', '/auth/confirm-email-change', [
            'json' => ['token' => $confirmToken],
        ]);
        $this->assertResponseIsSuccessful();

        // Pull the revert token straight out of the just-stamped row.
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $stored = $em->getRepository(EmailChangeRequest::class)->findOneBy([]);
        $this->assertNotNull($stored);
        $this->assertNotNull($stored->getRevertTokenHash());

        // The plain token is in the notice email body.
        $email = $this->getMailerMessage(0);
        if (!preg_match('#/revert-email-change\?token=([0-9a-f]+)#', $email->getTextBody(), $m)) {
            $this->fail('Revert email did not contain a revert link.');
        }
        $plainRevertToken = $m[1];

        $client->request('POST', '/auth/revert-email-change', [
            'json' => ['token' => $plainRevertToken],
        ]);
        $this->assertResponseIsSuccessful();

        $rolledBack = $this->reloadUser('old@example.com');
        $this->assertSame('old@example.com', $rolledBack->getEmail());
    }

    public function testRevertRejectsBadToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/auth/revert-email-change', [
            'json' => ['token' => 'nope'],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    // --- PATCH /users/{id} can no longer change email ---

    public function testPatchUserIgnoresEmailField(): void
    {
        $user = $this->createTestUser('old@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('PATCH', '/users/' . $user->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['email' => 'sneaky@example.com'],
        ]);

        // Whether API Platform 422s an unknown field or silently drops it,
        // the persisted email must be unchanged.
        $refreshed = $this->reloadUser('old@example.com');
        $this->assertSame('old@example.com', $refreshed->getEmail());
    }

    // --- Helpers ---

    /** @return array{0: EmailChangeRequest, 1: string} */
    private function seedRequest(
        User $user,
        string $newEmail,
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        $plainToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plainToken);
        $expiresAt ??= new \DateTimeImmutable('+1 hour');

        $request = new EmailChangeRequest($user, $user->getEmail(), $newEmail, $hash, $expiresAt);
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        return [$request, $plainToken];
    }

    private function createTestUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function reloadUser(string $email): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user, sprintf('User %s should exist', $email));

        return $user;
    }
}
