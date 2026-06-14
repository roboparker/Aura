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

/**
 * Coverage for the `app:segment` action branches not exercised by
 * {@see SegmentCommandTest}: list, show, enable, disable, roles (valid),
 * exclude, remove, delete, plus the obvious error paths (unknown action,
 * unknown key, missing key, missing args, duplicate-create).
 */
class SegmentCommandCoverageTest extends KernelTestCase
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

    public function testCreateViaCommandThenShow(): void
    {
        // "create" with name + purpose + roles options on a fresh key.
        $tester = $this->invoke([
            'action' => 'create',
            'args' => ['rollout-cohort'],
            '--name' => 'Rollout cohort',
            '--purpose' => Segment::PURPOSE_ROLLOUT,
            '--roles' => 'ROLE_ADMIN,ROLE_USER',
        ]);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        // create() ends by calling show(), so the definition list renders.
        $this->assertStringContainsString('ROLE_SEGMENT_ROLLOUT_COHORT', $display);

        $segment = $this->reload('rollout-cohort');
        $this->assertSame('Rollout cohort', $segment->getName());
        $this->assertSame(Segment::PURPOSE_ROLLOUT, $segment->getPurpose());
        $this->assertSame(['ROLE_ADMIN', 'ROLE_USER'], $segment->getIncludedRoles());
    }

    public function testShowAction(): void
    {
        $this->seedSegment('beta');
        $tester = $this->invoke(['action' => 'show', 'args' => ['beta']]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('ROLE_SEGMENT_BETA', $tester->getDisplay());
    }

    public function testListAction(): void
    {
        $this->seedSegment('alpha');
        $this->seedSegment('beta');

        $tester = $this->invoke(['action' => 'list', 'args' => []]);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertStringContainsString('alpha', $display);
        $this->assertStringContainsString('beta', $display);
    }

    public function testListActionWithNoSegments(): void
    {
        $tester = $this->invoke(['action' => 'list', 'args' => []]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('No segments yet', $tester->getDisplay());
    }

    public function testEnableAndDisableActions(): void
    {
        $this->seedSegment('toggle');

        $this->invoke(['action' => 'disable', 'args' => ['toggle']])->assertCommandIsSuccessful();
        $this->assertFalse($this->reload('toggle')->isEnabled());

        $this->invoke(['action' => 'enable', 'args' => ['toggle']])->assertCommandIsSuccessful();
        $this->assertTrue($this->reload('toggle')->isEnabled());
    }

    public function testRolloutZeroClearsPercentage(): void
    {
        $segment = $this->seedSegment('grad');
        $segment->setRolloutPercentage(40);
        $this->em->flush();

        $this->invoke(['action' => 'rollout', 'args' => ['grad', '0']])->assertCommandIsSuccessful();
        // 0% is stored as null per setRollout().
        $this->assertNull($this->reload('grad')->getRolloutPercentage());
    }

    public function testRolesActionSetsValidRoles(): void
    {
        $this->seedSegment('beta');
        $this->invoke(['action' => 'roles', 'args' => ['beta', 'ROLE_ADMIN,role_user']])
            ->assertCommandIsSuccessful();
        // parseRoles upper-cases + dedupes.
        $this->assertSame(['ROLE_ADMIN', 'ROLE_USER'], $this->reload('beta')->getIncludedRoles());
    }

    public function testExcludeCreatesManualExcludeOverride(): void
    {
        $segment = $this->seedSegment('beta');
        $this->makeUser('excluded@example.com');

        $this->invoke(['action' => 'exclude', 'args' => ['beta', 'excluded@example.com']])
            ->assertCommandIsSuccessful();

        $membership = $this->em->getRepository(SegmentMembership::class)
            ->findOneBy(['segment' => $segment]);
        assert($membership instanceof SegmentMembership);
        $this->assertSame(SegmentMembership::MODE_EXCLUDE, $membership->getMode());
    }

    public function testRemoveDropsOverride(): void
    {
        $segment = $this->seedSegment('beta');
        $this->makeUser('member@example.com');

        $this->invoke(['action' => 'add', 'args' => ['beta', 'member@example.com']])
            ->assertCommandIsSuccessful();
        $this->assertNotNull(
            $this->em->getRepository(SegmentMembership::class)->findOneBy(['segment' => $segment]),
        );

        $this->invoke(['action' => 'remove', 'args' => ['beta', 'member@example.com']])
            ->assertCommandIsSuccessful();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Segment::class)->findOneByKey('beta');
        assert($reloaded instanceof Segment);
        $this->assertNull(
            $this->em->getRepository(SegmentMembership::class)->findOneBy(['segment' => $reloaded]),
        );
    }

    public function testAddWithUnknownEmailFails(): void
    {
        $this->seedSegment('beta');
        // No matching user → nothing changed → FAILURE.
        $tester = $this->invoke(['action' => 'add', 'args' => ['beta', 'ghost@example.com']]);
        $this->assertNotSame(0, $tester->getStatusCode());
    }

    public function testAddWithoutEmailsIsInvalid(): void
    {
        $this->seedSegment('beta');
        $tester = $this->invoke(['action' => 'add', 'args' => ['beta']]);
        $this->assertNotSame(0, $tester->getStatusCode());
    }

    public function testRemoveWithoutOverrideFails(): void
    {
        $this->seedSegment('beta');
        $this->makeUser('member@example.com');
        // User exists but has no override → nothing removed → FAILURE.
        $tester = $this->invoke(['action' => 'remove', 'args' => ['beta', 'member@example.com']]);
        $this->assertNotSame(0, $tester->getStatusCode());
    }

    public function testDeleteAction(): void
    {
        $this->seedSegment('gone');
        $this->invoke(['action' => 'delete', 'args' => ['gone']])->assertCommandIsSuccessful();

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Segment::class)->findOneByKey('gone'));
    }

    public function testUnknownActionIsRejected(): void
    {
        $tester = $this->invoke(['action' => 'frobnicate', 'args' => []]);
        $this->assertNotSame(0, $tester->getStatusCode());
    }

    public function testUnknownKeyFails(): void
    {
        $tester = $this->invoke(['action' => 'show', 'args' => ['no-such-key']]);
        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No segment with key', $tester->getDisplay());
    }

    public function testMissingKeyIsInvalid(): void
    {
        $tester = $this->invoke(['action' => 'show', 'args' => []]);
        $this->assertNotSame(0, $tester->getStatusCode());
    }

    public function testRolloutMissingValueIsInvalid(): void
    {
        $this->seedSegment('grad');
        $tester = $this->invoke(['action' => 'rollout', 'args' => ['grad']]);
        $this->assertNotSame(0, $tester->getStatusCode());
    }

    public function testRolloutOutOfRangeIsInvalid(): void
    {
        $this->seedSegment('grad');
        $tester = $this->invoke(['action' => 'rollout', 'args' => ['grad', '150']]);
        $this->assertNotSame(0, $tester->getStatusCode());
    }

    public function testCreateDuplicateKeyFails(): void
    {
        $this->seedSegment('beta');
        $tester = $this->invoke(['action' => 'create', 'args' => ['beta']]);
        $this->assertNotSame(0, $tester->getStatusCode());
    }

    public function testCreateWithoutKeyIsInvalid(): void
    {
        $tester = $this->invoke(['action' => 'create', 'args' => []]);
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
