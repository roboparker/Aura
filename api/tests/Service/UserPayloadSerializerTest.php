<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Segment;
use App\Entity\User;
use App\Service\UserPayloadSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Locks the `/api/me` payload contract. This shape used to be hand-copied in
 * both AuthController and TwoFactorJsonHandler and drifted out of sync (the
 * 2FA path dropped segment roles, the waitlisted flag, and half the twoFactor
 * block). Both call sites now share UserPayloadSerializer; this test pins the
 * fields the PWA depends on so a future edit can't silently drop one again.
 */
class UserPayloadSerializerTest extends KernelTestCase
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

    private function serializer(): UserPayloadSerializer
    {
        return self::getContainer()->get(UserPayloadSerializer::class);
    }

    public function testPayloadCarriesEveryFieldThePwaConsumes(): void
    {
        $payload = $this->serializer()->serialize($this->makeUser('user@example.com'));

        // The contract surface the PWA reads on bootstrap / login.
        foreach (['id', 'email', 'roles', 'givenName', 'familyName', 'nickname', 'personalizedColor', 'avatarUrls', 'waitlisted', 'preferences', 'twoFactor'] as $key) {
            $this->assertArrayHasKey($key, $payload, sprintf('Missing top-level "%s".', $key));
        }

        $twoFactor = $payload['twoFactor'];
        $this->assertIsArray($twoFactor);
        foreach (['enabled', 'method', 'recoveryCodesRemaining', 'recoveryCodesTotal', 'enabledAt', 'lastVerifiedAt', 'recoveryPending'] as $key) {
            $this->assertArrayHasKey($key, $twoFactor, sprintf('Missing twoFactor."%s".', $key));
        }
    }

    public function testSegmentRolesAreAppendedForRegularUsers(): void
    {
        $user = $this->makeUser('regular@example.com');
        $this->makeFullRolloutSegment('beta-editor');

        $payload = $this->serializer()->serialize($user);

        $this->assertContains('ROLE_SEGMENT_BETA_EDITOR', $payload['roles']);
    }

    public function testWaitlistedUserGetsNoSegmentRoles(): void
    {
        $user = $this->makeUser('waitlisted@example.com');
        $user->setWaitlisted(true);
        $this->em->flush();
        $this->makeFullRolloutSegment('beta-editor');

        $payload = $this->serializer()->serialize($user);

        $this->assertTrue($payload['waitlisted']);
        $this->assertNotContains('ROLE_SEGMENT_BETA_EDITOR', $payload['roles']);
    }

    private function makeUser(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    private function makeFullRolloutSegment(string $key): Segment
    {
        $segment = new Segment();
        $segment->setKey($key);
        $segment->setName($key);
        $segment->setEnabled(true);
        $segment->setRolloutPercentage(100);
        $segment->setIncludedRoles([]);
        $this->em->persist($segment);
        $this->em->flush();
        return $segment;
    }
}
