<?php

namespace App\DataFixtures;

use App\Entity\GroupInvite;
use App\Entity\Space;
use App\Entity\SpaceInvite;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserInvite;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Seeds three predictable invite URLs so the four `InviteSignup` screens
 * are all reachable from the dev stack without manually generating a
 * token first:
 *
 *   /signup?invite=pending-invite-fixture   — pending, full attachments
 *   /signup?invite=expired-invite-fixture   — expired (past expiresAt)
 *   /signup?invite=accepted-invite-fixture  — already redeemed
 *
 * Tokens are stored as their sha256 hash (matching the production flow);
 * the URL above carries the plain text. Don't use these tokens in any
 * real deployment — they're documented in source.
 *
 * The pending invite attaches the new signup to the Launch team space
 * (member) + the Engineering group, so the form-mode card renders both
 * the "SPACE · MEMBER" and the "GROUP · N ppl" rows.
 */
class InviteFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            BoardFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var User $uma */
        $uma = $this->getReference(UserFixtures::USER_REFERENCE, User::class);
        /** @var User $noah */
        $noah = $this->getReference(UserFixtures::TEAM_USER_REFERENCES[0], User::class);
        /** @var Space $launchSpace */
        $launchSpace = $this->getReference(BoardFixtures::LAUNCH_SPACE_REFERENCE, Space::class);
        /** @var UserGroup $engineering */
        $engineering = $this->getReference(BoardFixtures::ENGINEERING_GROUP_REFERENCE, UserGroup::class);

        $now = new \DateTimeImmutable();

        // 1. Pending — links a new signup to a space + a group, invited by Uma.
        $pending = new UserInvite(
            'newcomer@madori.test',
            hash('sha256', 'pending-invite-fixture'),
            $now->modify('+6 days'),
        );
        $manager->persist($pending);
        $manager->persist(new SpaceInvite($pending, $launchSpace, $uma, Space::ROLE_MEMBER));
        $manager->persist(new GroupInvite($pending, $engineering, $uma));

        // 2. Expired — same shape but expiresAt rolled back two weeks past.
        //    Invited by Noah so the expired screen's "ask the sender" copy
        //    isn't always Uma.
        $expired = new UserInvite(
            'expired-invite@madori.test',
            hash('sha256', 'expired-invite-fixture'),
            $now->modify('-7 days'),
        );
        $manager->persist($expired);
        $manager->persist(new SpaceInvite($expired, $launchSpace, $noah, Space::ROLE_MEMBER));

        // 3. Accepted — token resolves, but `acceptedAt` is set so the
        //    lookup returns status='accepted' and the PWA shows the
        //    "this invite was already used" screen.
        $accepted = new UserInvite(
            'accepted-invite@madori.test',
            hash('sha256', 'accepted-invite-fixture'),
            $now->modify('+6 days'),
        );
        $accepted->markAccepted();
        $manager->persist($accepted);
        $manager->persist(new SpaceInvite($accepted, $launchSpace, $uma, Space::ROLE_MEMBER));

        $manager->flush();
    }
}
