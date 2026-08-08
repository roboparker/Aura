<?php

namespace App\Tests\Command;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The manual half of the organization purge (#billing Phase 1c). This is a
 * cascading delete of whole organizations, so `--dry-run` — being able to look
 * before pulling the trigger — is behaviour worth pinning, not a convenience.
 */
class OrganizationPurgeCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->createQuery('DELETE FROM App\Entity\OrganizationMembership')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Organization')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testReportsNothingDueWhenNoOrgIsDeleted(): void
    {
        $this->makeOrg('Live Co', deletedDaysAgo: null);

        $tester = $this->invoke();
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('No organizations are due', $tester->getDisplay());
    }

    public function testSkipsAnOrgStillInsideItsGracePeriod(): void
    {
        $org = $this->makeOrg('Recent Co', deletedDaysAgo: 1);

        $this->invoke()->assertCommandIsSuccessful();

        $this->em->clear();
        $this->assertNotNull($this->find($org), 'an org inside its window must survive');
    }

    public function testDryRunListsWithoutDeleting(): void
    {
        $org = $this->makeOrg('Lapsed Co', deletedDaysAgo: 40);

        $tester = $this->invoke(dryRun: true);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Lapsed Co', $tester->getDisplay());
        $this->assertStringContainsString('would be purged', $tester->getDisplay());

        $this->em->clear();
        $this->assertNotNull($this->find($org), '--dry-run must not delete anything');
    }

    public function testPurgesAnOrgPastItsGracePeriod(): void
    {
        $org = $this->makeOrg('Lapsed Co', deletedDaysAgo: 40);

        $tester = $this->invoke();
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Purged 1', $tester->getDisplay());

        $this->em->clear();
        $this->assertNull($this->find($org));
    }

    private function find(Organization $org): ?Organization
    {
        return $this->em->getRepository(Organization::class)->find($org->getId());
    }

    private function makeOrg(string $name, ?int $deletedDaysAgo): Organization
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $owner = new User();
        $owner->setEmail(strtolower(str_replace(' ', '-', $name)) . '@example.com');
        $owner->setRoles(['ROLE_USER']);
        $owner->setGivenName('Test');
        $owner->setFamilyName('User');
        $owner->setPersonalizedColor('#0369a1');
        $owner->setPassword($hasher->hashPassword($owner, 'Password123!@#'));
        $this->em->persist($owner);

        $org = (new Organization())
            ->setName($name)
            ->setSlug('o-' . bin2hex(random_bytes(4)))
            ->setCreatedBy($owner);
        $org->addMember($owner, Organization::ROLE_OWNER);

        if (null !== $deletedDaysAgo) {
            $deletedAt = new \DateTimeImmutable(sprintf('-%d days', $deletedDaysAgo));
            // purgeAfter is stored, not derived, so the fixture sets both —
            // exactly what softDelete() would have written at the time.
            $org->markDeleted($deletedAt, $deletedAt->modify('+30 days'));
        }

        $this->em->persist($org);
        $this->em->flush();

        return $org;
    }

    private function invoke(bool $dryRun = false): CommandTester
    {
        $kernel = self::$kernel;
        assert(null !== $kernel);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:organizations:purge'));
        $input = [];
        if ($dryRun) {
            $input['--dry-run'] = true;
        }
        $tester->execute($input);

        return $tester;
    }
}
