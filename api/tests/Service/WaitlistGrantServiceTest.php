<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\WaitlistGrantService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class WaitlistGrantServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WaitlistGrantService $granter;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();

        $granter = self::getContainer()->get(WaitlistGrantService::class);
        assert($granter instanceof WaitlistGrantService);
        $this->granter = $granter;
    }

    public function testGrantPromotesWaitlistedAndSkipsFullAccounts(): void
    {
        $waiting = $this->makeUser('waiting@example.com', waitlisted: true);
        $full = $this->makeUser('full@example.com', waitlisted: false);

        $result = $this->granter->grant([$waiting, $full]);

        $this->assertSame([$waiting], $result->promoted);
        $this->assertSame([$full], $result->skipped);
        $this->assertFalse($this->reload('waiting@example.com')->isWaitlisted());
    }

    public function testGrantDryRunReportsButChangesNothing(): void
    {
        $waiting = $this->makeUser('dry@example.com', waitlisted: true);

        $result = $this->granter->grant([$waiting], dryRun: true);

        $this->assertSame([$waiting], $result->promoted);
        // No flag flip — the split is computed without the side effects.
        $this->assertTrue($this->reload('dry@example.com')->isWaitlisted());
    }

    public function testFindWaitlistedReturnsOldestFirstAndHonoursLimit(): void
    {
        // UUIDv7 ids are time-ordered, so creation order is oldest-first.
        $first = $this->makeUser('first@example.com', waitlisted: true);
        $second = $this->makeUser('second@example.com', waitlisted: true);
        $this->makeUser('third@example.com', waitlisted: true);
        $this->makeUser('full@example.com', waitlisted: false);

        $oldestTwo = $this->granter->findWaitlisted(2);

        $this->assertSame(
            [(string) $first->getId(), (string) $second->getId()],
            array_map(static fn (User $u): string => (string) $u->getId(), $oldestTwo),
        );
        $this->assertCount(3, $this->granter->findWaitlisted());
    }

    public function testResolveSelectorsMixesIdsAndEmailsDedupesAndReportsUnknown(): void
    {
        $user = $this->makeUser('hit@example.com', waitlisted: true);
        $id = (string) $user->getId();

        $resolved = $this->granter->resolveSelectors([$id, 'hit@example.com', 'missing@example.com']);

        // The id + email referencing the same user collapse to one entry.
        $this->assertSame([$user], $resolved['users']);
        $this->assertSame(['missing@example.com'], $resolved['notFound']);
    }

    private function makeUser(string $email, bool $waitlisted): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $user->setWaitlisted($waitlisted);
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    private function reload(string $email): User
    {
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        assert($user instanceof User);
        return $user;
    }
}
