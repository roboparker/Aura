<?php

namespace App\Tests\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UsageSnapshotCommandTest extends KernelTestCase
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

    public function testRunsWithNoOptions(): void
    {
        $this->invoke([])->assertCommandIsSuccessful();
    }

    public function testRunsWithDateOption(): void
    {
        $this->invoke(['--date' => '2026-06-10'])->assertCommandIsSuccessful();
    }

    public function testInvalidDateFails(): void
    {
        $tester = $this->invoke(['--date' => 'not-a-date']);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $input
     */
    private function invoke(array $input): CommandTester
    {
        $kernel = self::$kernel;
        assert(null !== $kernel);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:usage:snapshot'));
        $tester->execute($input);
        return $tester;
    }
}
