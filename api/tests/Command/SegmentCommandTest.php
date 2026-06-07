<?php

namespace App\Tests\Command;

use App\Entity\Segment;
use App\Entity\SegmentMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SegmentCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->createQuery('DELETE FROM App\Entity\SegmentMembership')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Segment')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testCreateBuildsSegmentWithDerivedRole(): void
    {
        $tester = $this->invoke(['action' => 'create', 'args' => ['beta-new-editor'], '--rollout' => '25']);
        $tester->assertCommandIsSuccessful();

        $segment = $this->reload('beta-new-editor');
        $this->assertSame('ROLE_SEGMENT_BETA_NEW_EDITOR', $segment->getRole());
        $this->assertSame(25, $segment->getRolloutPercentage());
    }

    public function testCreateRejectsInvalidKey(): void
    {
        $tester = $this->invoke(['action' => 'create', 'args' => ['Bad_Key']]);
        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertNull($this->em->getRepository(Segment::class)->findOneByKey('Bad_Key'));
    }

    public function testRolloutActionSetsPercentage(): void
    {
        $this->seedSegment('grad');
        $this->invoke(['action' => 'rollout', 'args' => ['grad', '60']])->assertCommandIsSuccessful();

        $this->assertSame(60, $this->reload('grad')->getRolloutPercentage());
    }

    public function testAddCreatesManualIncludeOverride(): void
    {
        $segment = $this->seedSegment('beta');
        $this->makeUser('member@example.com');

        $this->invoke(['action' => 'add', 'args' => ['beta', 'member@example.com']])
            ->assertCommandIsSuccessful();

        $membership = $this->em->getRepository(SegmentMembership::class)
            ->findOneBy(['segment' => $segment]);
        assert($membership instanceof SegmentMembership);
        $this->assertSame(SegmentMembership::MODE_INCLUDE, $membership->getMode());
    }

    public function testRolesActionRejectsSegmentRole(): void
    {
        $this->seedSegment('beta');
        $tester = $this->invoke(['action' => 'roles', 'args' => ['beta', 'ROLE_SEGMENT_OTHER']]);
        $this->assertNotSame(0, $tester->getStatusCode());
    }

    /**
     * @param array<string, string|list<string>> $input
     */
    private function invoke(array $input): CommandTester
    {
        $kernel = self::$kernel;
        assert(null !== $kernel);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:segment'));
        $tester->execute($input);
        return $tester;
    }

    private function seedSegment(string $key): Segment
    {
        $segment = new Segment();
        $segment->setKey($key);
        $segment->setName($key);
        $this->em->persist($segment);
        $this->em->flush();
        return $segment;
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

    private function reload(string $key): Segment
    {
        $this->em->clear();
        $segment = $this->em->getRepository(Segment::class)->findOneByKey($key);
        assert($segment instanceof Segment);
        return $segment;
    }
}
