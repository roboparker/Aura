<?php

namespace App\Tests\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class DispatchTaskRemindersCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
    }

    public function testRunsOnEmptyDatabase(): void
    {
        $kernel = self::$kernel;
        assert(null !== $kernel);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:tasks:reminders:dispatch'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('notification', $tester->getDisplay());
    }
}
