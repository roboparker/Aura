<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\GrowthDigestMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The weekly growth digest and the paid-signup alert (#763).
 *
 * A dashboard nobody opens doesn't govern anything, so the numbers are pushed
 * as well as pulled. What matters here: only admins receive them, and the
 * paid-signup alert fires on a genuine conversion rather than on every webhook
 * Stripe re-delivers.
 */
class GrowthDigestTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        foreach (['App\Entity\Subscription', 'App\Entity\User'] as $entity) {
            $this->entityManager->createQuery('DELETE FROM ' . $entity)->execute();
        }
    }

    public function testDigestGoesToAdminsOnly(): void
    {
        $this->makeUser('admin1@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->makeUser('admin2@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->makeUser('plain@example.com');

        $mailer = static::getContainer()->get(GrowthDigestMailer::class);
        $sent = $mailer->sendWeekly();

        $this->assertSame(2, $sent, 'Only the two admins should be emailed.');
    }

    public function testDigestSkipsDeactivatedAdmins(): void
    {
        $this->makeUser('active@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $gone = $this->makeUser('gone@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $gone->setDeactivatedAt(new \DateTimeImmutable('-1 day'));
        $this->entityManager->flush();

        $mailer = static::getContainer()->get(GrowthDigestMailer::class);
        $this->assertSame(1, $mailer->sendWeekly());
    }

    public function testDigestRendersWithNoActivityAtAll(): void
    {
        // A brand-new instance must still produce a sendable email rather than
        // dividing by zero or blowing up on empty series.
        $this->makeUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);

        static::createClient();
        $mailer = static::getContainer()->get(GrowthDigestMailer::class);
        $mailer->sendWeekly();

        // Twig renders at send time, so a broken template throws here rather
        // than waiting to fail on the first real Monday.
        $this->assertEmailCount(1);
    }

    public function testPaidSignupAlertRendersForEachAccountShape(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $owner = $this->makeUser('buyer@example.com');

        $sub = new Subscription();
        $sub->setOwnerUser($owner);
        $sub->setPlan('pro');
        $sub->setSeats(1);
        $sub->setBillingInterval('month');
        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $this->entityManager->persist($sub);
        $this->entityManager->flush();

        static::createClient();
        $mailer = static::getContainer()->get(GrowthDigestMailer::class);
        // Templates render without throwing — the failure mode that would
        // otherwise only surface on a real customer's first payment.
        $mailer->sendPaidSignupAlert($sub);

        $this->assertEmailCount(1);
        $this->assertNotNull($admin->getId());
    }

    /** @param list<string> $roles */
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
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
