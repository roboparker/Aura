<?php

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class WaitlistGrantCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testGrantsByEmail(): void
    {
        $this->makeWaitlisted('a@example.com');
        $this->makeWaitlisted('b@example.com');

        $tester = $this->invoke(['a@example.com']);
        $tester->assertCommandIsSuccessful();

        $this->assertFalse($this->reload('a@example.com')->isWaitlisted());
        // The unselected user is untouched.
        $this->assertTrue($this->reload('b@example.com')->isWaitlisted());
    }

    public function testGrantsByIdAndDedupesAcrossIdAndEmail(): void
    {
        $user = $this->makeWaitlisted('c@example.com');
        $id = (string) $user->getId();

        // Same user referenced twice (id + email) collapses to one promotion.
        $tester = $this->invoke([$id, 'c@example.com']);
        $tester->assertCommandIsSuccessful();

        $this->assertFalse($this->reload('c@example.com')->isWaitlisted());
    }

    public function testAllPromotesEveryWaitlistedUser(): void
    {
        $this->makeWaitlisted('one@example.com');
        $this->makeWaitlisted('two@example.com');
        // A full account is left out of the waitlist entirely.
        $this->makeWaitlisted('three@example.com', waitlisted: false);

        $tester = $this->invoke([], ['--all' => true]);
        $tester->assertCommandIsSuccessful();

        $this->assertFalse($this->reload('one@example.com')->isWaitlisted());
        $this->assertFalse($this->reload('two@example.com')->isWaitlisted());
    }

    public function testOldestPromotesTheLongestWaitingN(): void
    {
        // UUIDv7 ids are time-ordered, so creation order == oldest-first order.
        $first = $this->makeWaitlisted('first@example.com');
        $second = $this->makeWaitlisted('second@example.com');
        $third = $this->makeWaitlisted('third@example.com');

        $tester = $this->invoke([], ['--oldest' => '2']);
        $tester->assertCommandIsSuccessful();

        $this->assertFalse($this->reload('first@example.com')->isWaitlisted());
        $this->assertFalse($this->reload('second@example.com')->isWaitlisted());
        // The newest signup is still waiting.
        $this->assertTrue($this->reload('third@example.com')->isWaitlisted());
    }

    public function testDryRunChangesNothing(): void
    {
        $this->makeWaitlisted('preview@example.com');

        $tester = $this->invoke(['preview@example.com'], ['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        $this->assertTrue($this->reload('preview@example.com')->isWaitlisted());
    }

    public function testRequiresExactlyOneMode(): void
    {
        $this->assertSame(Command::INVALID, $this->invoke([])->getStatusCode());
        $this->assertSame(
            Command::INVALID,
            $this->invoke(['x@example.com'], ['--all' => true])->getStatusCode(),
        );
    }

    public function testRejectsNonPositiveOldest(): void
    {
        $this->assertSame(Command::INVALID, $this->invoke([], ['--oldest' => '0'])->getStatusCode());
        $this->assertSame(Command::INVALID, $this->invoke([], ['--oldest' => 'abc'])->getStatusCode());
    }

    public function testAlreadyFullAccountIsSkippedNotErrored(): void
    {
        $this->makeWaitlisted('full@example.com', waitlisted: false);

        $tester = $this->invoke(['full@example.com']);
        // No waitlisted users in the selection: a clean no-op, not a failure.
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFalse($this->reload('full@example.com')->isWaitlisted());
    }

    /**
     * @param list<string>         $selectors
     * @param array<string, mixed> $options
     */
    private function invoke(array $selectors, array $options = []): CommandTester
    {
        $kernel = self::$kernel;
        assert(null !== $kernel);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:waitlist:grant'));
        $tester->execute(['selectors' => $selectors, ...$options]);
        return $tester;
    }

    private function makeWaitlisted(string $email, bool $waitlisted = true): User
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
