<?php

namespace App\Tests\Service;

use App\Calendar\CalendarClientRegistry;
use App\Entity\CalendarSubscription;
use App\Entity\OAuthConnection;
use App\Entity\User;
use App\Service\CalendarSubscriptionManager;
use App\Tests\Calendar\InMemoryCalendarClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Change-notification subscription lifecycle (#582 Phase D). The manager is
 * dark-launched (no-op without a webhook URL); these drive the enabled path
 * through the in-memory subscriber recorder.
 */
class CalendarSubscriptionManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->createQuery('DELETE FROM App\Entity\CalendarSubscription')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\OAuthConnection')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->recorder()->reset();
    }

    public function testDisabledManagerIsNoOp(): void
    {
        $connection = $this->connection();
        $this->manager('')->ensure($connection);

        $this->assertNull($this->em->getRepository(CalendarSubscription::class)->findOneBy(['connection' => $connection]));
        $this->assertSame([], $this->recorder()->subscriptions);
    }

    public function testEnsureCreatesSubscription(): void
    {
        $connection = $this->connection();
        $this->manager('https://x.test')->ensure($connection);

        $subscription = $this->em->getRepository(CalendarSubscription::class)->findOneBy(['connection' => $connection]);
        $this->assertNotNull($subscription);
        $this->assertSame('google', $subscription->getProvider());
        $this->assertNotSame('', $subscription->getChannelId());
        $this->assertNotSame('', $subscription->getSecret());
        $this->assertCount(1, $this->recorder()->subscriptions);
    }

    public function testEnsureIsIdempotentWhileHealthy(): void
    {
        $connection = $this->connection();
        $manager = $this->manager('https://x.test');
        $manager->ensure($connection);
        $manager->ensure($connection);

        // Second call sees a healthy (far-from-expiry) subscription → no re-subscribe.
        $this->assertCount(1, $this->recorder()->subscriptions);
    }

    public function testRemoveDeletesRowAndStopsChannel(): void
    {
        $connection = $this->connection();
        $manager = $this->manager('https://x.test');
        $manager->ensure($connection);

        $manager->remove($connection);

        $this->assertNull($this->em->getRepository(CalendarSubscription::class)->findOneBy(['connection' => $connection]));
        $ops = array_column($this->recorder()->subscriptions, 'op');
        $this->assertContains('unsubscribe', $ops);
    }

    public function testRemoveWithoutSubscriptionIsNoOp(): void
    {
        // No ensure() first → nothing to tear down.
        $this->manager('https://x.test')->remove($this->connection());
        $this->assertSame([], $this->recorder()->subscriptions);
    }

    public function testRenewExpiringResubscribesNearExpiry(): void
    {
        $connection = $this->connection();
        $manager = $this->manager('https://x.test');
        $manager->ensure($connection);

        // Backdate the subscription to just inside the renewal window.
        $subscription = $this->em->getRepository(CalendarSubscription::class)->findOneBy(['connection' => $connection]);
        $this->assertNotNull($subscription);
        $subscription->setExpiresAt(new \DateTimeImmutable('+1 hour'));
        $this->em->flush();
        $this->recorder()->reset();

        $manager->renewExpiring();

        $ops = array_column($this->recorder()->subscriptions, 'op');
        $this->assertContains('subscribe', $ops);
    }

    public function testRenewExpiringDisabledIsNoOp(): void
    {
        $this->manager('')->renewExpiring();
        $this->assertSame([], $this->recorder()->subscriptions);
    }

    private function manager(string $webhookBaseUrl): CalendarSubscriptionManager
    {
        $registry = self::getContainer()->get(CalendarClientRegistry::class);

        return new CalendarSubscriptionManager($this->em, $registry, new NullLogger(), $webhookBaseUrl);
    }

    private function recorder(): InMemoryCalendarClient
    {
        return self::getContainer()->get(InMemoryCalendarClient::class);
    }

    private function connection(): OAuthConnection
    {
        $user = new User();
        $user->setEmail('sub-' . uniqid() . '@example.com');
        $user->setGivenName('Sub');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $this->em->persist($user);

        $connection = (new OAuthConnection('google'))
            ->setUser($user)
            ->setEmail($user->getEmail())
            ->setAccessToken('enc')
            ->setExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setScopes(['calendar']);
        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }
}
