<?php

namespace App\Tests\Command;

use App\Entity\User;
use App\Entity\UserUsageSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UsageReportCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->createQuery('DELETE FROM App\Entity\UserUsageSnapshot')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testEmptyDatabaseWarnsNoSnapshots(): void
    {
        $tester = $this->invoke();
        $tester->assertCommandIsSuccessful();

        $this->assertStringContainsString('No usage snapshots', $tester->getDisplay());
    }

    public function testListsUserWithSnapshot(): void
    {
        $user = $this->makeUser('usage@example.com');

        $snapshot = new UserUsageSnapshot();
        $snapshot->setUser($user);
        $snapshot->setCapturedOn(new \DateTimeImmutable('today', new \DateTimeZone('UTC')));
        $snapshot->setDiskBytes(2048);
        $snapshot->setDbBytesEst(1024);
        $snapshot->setApiCalls(10);
        $snapshot->setMcpCalls(5);
        $this->em->persist($snapshot);
        $this->em->flush();

        $tester = $this->invoke();
        $tester->assertCommandIsSuccessful();

        $this->assertStringContainsString('usage@example.com', $tester->getDisplay());
    }

    private function invoke(): CommandTester
    {
        $kernel = self::$kernel;
        assert(null !== $kernel);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:usage:report'));
        $tester->execute([]);
        return $tester;
    }

    private function makeUser(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
}
