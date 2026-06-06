<?php

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserRoleCommandTest extends KernelTestCase
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

    public function testAddNormalizesBareRoleAndGrantsIt(): void
    {
        $this->makeUser('owner@example.com');

        $tester = $this->invoke('add', 'admin', ['owner@example.com']);
        $tester->assertCommandIsSuccessful();

        $this->assertContains('ROLE_ADMIN', $this->reload('owner@example.com')->getRoles());
    }

    public function testRemoveStripsRole(): void
    {
        $user = $this->makeUser('a@example.com');
        $user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        $this->invoke('remove', 'ROLE_ADMIN', ['a@example.com'])->assertCommandIsSuccessful();

        $this->assertNotContains('ROLE_ADMIN', $this->reload('a@example.com')->getRoles());
    }

    public function testSetReplacesAcrossMultipleUsers(): void
    {
        $u1 = $this->makeUser('one@example.com');
        $u1->setRoles(['ROLE_EDITOR']);
        $this->makeUser('two@example.com');
        $this->em->flush();

        $this->invoke('set', 'ROLE_ADMIN', ['one@example.com', 'two@example.com'])->assertCommandIsSuccessful();

        $one = $this->reload('one@example.com')->getRoles();
        $this->assertContains('ROLE_ADMIN', $one);
        $this->assertNotContains('ROLE_EDITOR', $one);
        $this->assertContains('ROLE_ADMIN', $this->reload('two@example.com')->getRoles());
    }

    public function testReservedRoleIsRejected(): void
    {
        $this->makeUser('x@example.com');

        $tester = $this->invoke('add', 'ROLE_USER', ['x@example.com']);

        $this->assertNotSame(0, $tester->getStatusCode());
    }

    /**
     * @param list<string> $emails
     */
    private function invoke(string $action, string $role, array $emails): CommandTester
    {
        $kernel = self::$kernel;
        assert(null !== $kernel);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:user:role'));
        $tester->execute([
            'action' => $action,
            'role' => $role,
            'emails' => $emails,
        ]);
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

    private function reload(string $email): User
    {
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        assert($user instanceof User);
        return $user;
    }
}
