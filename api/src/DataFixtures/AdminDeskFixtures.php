<?php

namespace App\DataFixtures;

use App\CustomField\CustomFieldKind;
use App\Entity\Comment;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Discussion;
use App\Entity\Page;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Admin (Ada) demo data — two spaces so an admin sign-in is interesting:
 *
 *  - **Admin desk** — a fully-populated shared space with at least one of
 *    every content type (project + tasks + a custom field & value, a page
 *    with a sub-page, a discussion + comment, tags), plus a few member users
 *    and a couple of groups.
 *  - **Sandbox** — a deliberately empty shared space, so the empty-state UI
 *    is reachable without deleting anything.
 *
 * Admin (Ada) is intentionally not a member of the user-side "Launch team"
 * space ({@see ProjectFixtures}); her data lives here.
 */
class AdminDeskFixtures extends Fixture implements DependentFixtureInterface
{
    public const ADMIN_DESK_SPACE_REFERENCE = 'space-admin-desk';

    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var User $ada */
        $ada = $this->getReference(UserFixtures::ADMIN_REFERENCE, User::class);
        /** @var User $noah */
        $noah = $this->getReference('user-noah', User::class);
        /** @var User $emma */
        $emma = $this->getReference('user-emma', User::class);
        /** @var User $liam */
        $liam = $this->getReference('user-liam', User::class);

        // --- Admin desk: a fully-populated shared space ---
        $desk = (new Space())
            ->setName('Admin desk')
            ->setDescription(
                'Housekeeping, account chores, and one-off admin tasks — the '
                . 'workspace that keeps the lights on.',
            )
            ->setCreatedBy($ada);
        $manager->persist($desk);
        $manager->flush();
        $this->addReference(self::ADMIN_DESK_SPACE_REFERENCE, $desk);

        // Members: Ada (admin) plus a few teammates.
        $manager->persist((new SpaceMembership())->setSpace($desk)->setUser($ada)->setRole(Space::ROLE_ADMIN));
        foreach ([$noah, $emma, $liam] as $member) {
            $manager->persist(
                (new SpaceMembership())->setSpace($desk)->setUser($member)->setRole(Space::ROLE_MEMBER),
            );
        }

        // Tags (space-scoped, owned by Ada).
        $tags = [];
        foreach (['ops' => '#0d9488', 'finance' => '#f59e0b'] as $title => $color) {
            $tag = (new Tag())->setOwner($ada)->setSpace($desk)->setTitle($title)->setColor($color);
            $manager->persist($tag);
            $tags[$title] = $tag;
        }

        // Project.
        $project = (new Project())
            ->setOwner($ada)
            ->setSpace($desk)
            ->setTitle('Admin checklist')
            ->setDescription(
                "Recurring admin chores so /projects isn't empty on an admin "
                . "sign-in.\n\n- Rotate backups\n- Review access\n- Reconcile invoices",
            );
        $manager->persist($project);

        // Custom field on the project (text). setProject() stamps the space and
        // attaches the field to the project's many-to-many.
        $field = (new CustomFieldDefinition())
            ->setProject($project)
            ->setName('Owner team')
            ->setKind(CustomFieldKind::TEXT->value)
            ->setSubtype('text')
            ->setConfig(['multi' => false])
            ->setPosition(0);
        $manager->persist($field);

        // Tasks — open, with a custom-field value, and a completed one.
        $t1 = (new Task())
            ->setOwner($ada)->setProject($project)
            ->setTitle('Rotate nightly backups')
            ->setDescription('Confirm the pg_dump + media tarball ran and prune to the newest 5.')
            ->setPosition(0);
        $t1->addTag($tags['ops']);
        $t1->addAssignee($ada);
        $t1->addCustomFieldValue((new CustomFieldValue())->setDefinition($field)->setValue('Platform'));
        $manager->persist($t1);

        $t2 = (new Task())
            ->setOwner($ada)->setProject($project)
            ->setTitle('Reconcile March invoices')
            ->setDescription('Match Stripe payouts against the ledger.')
            ->setPosition(1);
        $t2->addTag($tags['finance']);
        $manager->persist($t2);

        $t3 = (new Task())
            ->setOwner($ada)->setProject($project)
            ->setTitle('Review who has admin')
            ->setDescription('Quarterly access review — drop anyone who no longer needs it.')
            ->setPosition(2)
            ->setCompletedOn(new \DateTimeImmutable('-3 days'));
        $manager->persist($t3);

        // Page with a sub-page.
        $runbook = (new Page())
            ->setSpace($desk)->setCreatedBy($ada)
            ->setTitle('Ops runbook')
            ->setBody("# Ops runbook\n\nHow to keep the lights on.\n\n## Backups\nNightly at 02:00 UTC.")
            ->setPosition(0);
        $manager->persist($runbook);
        $manager->flush(); // need the parent id before linking the child

        $child = (new Page())
            ->setSpace($desk)->setCreatedBy($ada)->setParent($runbook)
            ->setTitle('Restoring from a backup')
            ->setBody('Steps to restore the latest pg_dump + media tarball.')
            ->setPosition(0);
        $manager->persist($child);

        // Discussion + a reply.
        $discussion = (new Discussion())
            ->setSpace($desk)->setAuthor($ada)
            ->setTitle('Welcome to the admin desk')
            ->setBody('Use this space for housekeeping — ping me with questions.')
            ->setCategory(Discussion::CATEGORY_ANNOUNCEMENTS)
            ->setIsPinned(true);
        $manager->persist($discussion);

        $comment = (new Comment())
            ->setDiscussion($discussion)->setAuthor($noah)
            ->setBody('Thanks Ada — added the backup rotation step to the runbook.');
        $manager->persist($comment);

        // Groups owned by this space (#groups-space).
        $ops = (new UserGroup())->setSpace($desk)->setTitle('Ops crew')->setSlug('ops')->setColor('#0d9488');
        $ops->addMember($ada);
        $ops->addMember($noah);
        $manager->persist($ops);

        $finance = (new UserGroup())->setSpace($desk)->setTitle('Finance')->setSlug('finance')->setColor('#f59e0b');
        $finance->addMember($ada);
        $finance->addMember($emma);
        $manager->persist($finance);

        // --- Sandbox: an intentionally empty shared space ---
        $sandbox = (new Space())
            ->setName('Sandbox')
            ->setDescription('An empty space — nothing here yet. Handy for trying the empty states.')
            ->setCreatedBy($ada);
        $manager->persist($sandbox);
        $manager->flush();
        $manager->persist(
            (new SpaceMembership())->setSpace($sandbox)->setUser($ada)->setRole(Space::ROLE_ADMIN),
        );

        $manager->flush();
    }
}
