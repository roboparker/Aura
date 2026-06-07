<?php

namespace App\Tests\Service;

use App\Entity\Segment;
use App\Entity\SegmentMembership;
use App\Entity\User;
use App\Service\SegmentEvaluator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SegmentEvaluatorTest extends KernelTestCase
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

    private function evaluator(): SegmentEvaluator
    {
        return self::getContainer()->get(SegmentEvaluator::class);
    }

    public function testDisabledSegmentMatchesNobody(): void
    {
        $user = $this->makeUser('a@example.com');
        $segment = $this->makeSegment('full', rollout: 100, enabled: false);

        $this->assertFalse($this->evaluator()->isUserInSegment($user, 'full'));
        $this->assertNotContains($segment->getRole(), $this->evaluator()->activeSegmentRolesFor($user));
    }

    public function testHundredPercentRolloutIncludesEveryone(): void
    {
        $user = $this->makeUser('a@example.com');
        $this->makeSegment('everyone', rollout: 100);

        $this->assertTrue($this->evaluator()->isUserInSegment($user, 'everyone'));
    }

    public function testZeroRolloutAndNoRulesIncludesNobody(): void
    {
        $user = $this->makeUser('a@example.com');
        $this->makeSegment('nobody', rollout: 0);

        $this->assertFalse($this->evaluator()->isUserInSegment($user, 'nobody'));
    }

    public function testRoleRuleIncludesMatchingUsers(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $plain = $this->makeUser('plain@example.com');
        $this->makeSegment('admins', includedRoles: ['ROLE_ADMIN']);

        $this->assertTrue($this->evaluator()->isUserInSegment($admin, 'admins'));
        $this->assertFalse($this->evaluator()->isUserInSegment($plain, 'admins'));
    }

    public function testManualExcludeWinsOverFullRollout(): void
    {
        $user = $this->makeUser('a@example.com');
        $segment = $this->makeSegment('full', rollout: 100);
        $this->addMembership($segment, $user, SegmentMembership::MODE_EXCLUDE);

        $this->assertFalse($this->evaluator()->isUserInSegment($user, 'full'));
    }

    public function testManualIncludeWinsOverZeroRollout(): void
    {
        $user = $this->makeUser('a@example.com');
        $segment = $this->makeSegment('hand-picked', rollout: 0);
        $this->addMembership($segment, $user, SegmentMembership::MODE_INCLUDE);

        $this->assertTrue($this->evaluator()->isUserInSegment($user, 'hand-picked'));
    }

    public function testActiveSegmentRolesUsesRolePrefix(): void
    {
        $user = $this->makeUser('a@example.com');
        $this->makeSegment('beta-new-editor', rollout: 100);

        $this->assertSame(
            ['ROLE_SEGMENT_BETA_NEW_EDITOR'],
            $this->evaluator()->activeSegmentRolesFor($user),
        );
    }

    public function testRolloutIsDeterministicAndMonotonic(): void
    {
        $user = $this->makeUser('stable@example.com');
        $segment = $this->makeSegment('grad', rollout: 100);

        // Find the lowest percentage at which this user is included, then
        // confirm membership never flips back off as the percentage rises.
        $firstIn = null;
        for ($pct = 1; $pct <= 100; ++$pct) {
            $segment->setRolloutPercentage($pct);
            $this->em->flush();
            $in = $this->evaluator()->isUserInSegment($user, 'grad');
            if ($in && null === $firstIn) {
                $firstIn = $pct;
            }
            if (null !== $firstIn) {
                $this->assertTrue($in, sprintf('User dropped out at %d%% after joining at %d%%.', $pct, $firstIn));
            }
        }
        $this->assertNotNull($firstIn, 'User should be included by 100%.');
    }

    /**
     * @param list<string> $roles
     */
    private function makeUser(string $email, array $roles = []): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    /**
     * @param list<string> $includedRoles
     */
    private function makeSegment(
        string $key,
        ?int $rollout = null,
        array $includedRoles = [],
        bool $enabled = true,
    ): Segment {
        $segment = new Segment();
        $segment->setKey($key);
        $segment->setName($key);
        $segment->setEnabled($enabled);
        $segment->setRolloutPercentage($rollout);
        $segment->setIncludedRoles($includedRoles);
        $this->em->persist($segment);
        $this->em->flush();
        return $segment;
    }

    private function addMembership(Segment $segment, User $user, string $mode): void
    {
        $membership = new SegmentMembership();
        $membership->setSegment($segment);
        $membership->setUser($user);
        $membership->setMode($mode);
        $this->em->persist($membership);
        $this->em->flush();
    }
}
