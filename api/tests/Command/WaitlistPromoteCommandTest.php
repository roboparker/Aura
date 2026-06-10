<?php

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class WaitlistPromoteCommandTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testPromotesWaitlistedUserAndSendsAccessEmail(): void
    {
        $this->makeUser('waiting@example.com', waitlisted: true);

        $tester = $this->invoke(['waiting@example.com']);
        $tester->assertCommandIsSuccessful();

        $this->assertFalse($this->reload('waiting@example.com')->isWaitlisted());
        $this->assertEmailCount(1);
    }

    public function testNoEmailFlagPromotesWithoutSendingEmail(): void
    {
        $this->makeUser('bootstrap@example.com', waitlisted: true);

        $tester = $this->invoke(['bootstrap@example.com'], noEmail: true);
        $tester->assertCommandIsSuccessful();

        $this->assertFalse($this->reload('bootstrap@example.com')->isWaitlisted());
        $this->assertEmailCount(0);
    }

    public function testPromotesMultipleUsers(): void
    {
        $this->makeUser('one@example.com', waitlisted: true);
        $this->makeUser('two@example.com', waitlisted: true);

        $this->invoke(['one@example.com', 'two@example.com'])->assertCommandIsSuccessful();

        $this->assertFalse($this->reload('one@example.com')->isWaitlisted());
        $this->assertFalse($this->reload('two@example.com')->isWaitlisted());
        $this->assertEmailCount(2);
    }

    public function testAlreadyActiveUserIsSkippedWithoutEmail(): void
    {
        $this->makeUser('active@example.com', waitlisted: false);

        $tester = $this->invoke(['active@example.com']);
        $tester->assertCommandIsSuccessful();

        $this->assertStringContainsString('already a full account', $tester->getDisplay());
        $this->assertEmailCount(0);
    }

    public function testUnknownEmailFails(): void
    {
        $tester = $this->invoke(['nobody@example.com']);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertEmailCount(0);
    }

    /**
     * @param list<string> $emails
     */
    private function invoke(array $emails, bool $noEmail = false): CommandTester
    {
        $kernel = self::$kernel;
        assert(null !== $kernel);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:waitlist:promote'));
        $input = ['emails' => $emails];
        if ($noEmail) {
            $input['--no-email'] = true;
        }
        $tester->execute($input);
        return $tester;
    }

    private function makeUser(string $email, bool $waitlisted): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setWaitlisted($waitlisted);
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
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
