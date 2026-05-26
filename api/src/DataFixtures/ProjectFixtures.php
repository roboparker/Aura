<?php

namespace App\DataFixtures;

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
 * Demo project + spaces / groups for fixture-driven dev and E2E. Uma
 * owns the "Launch team" space along with four team members (Noah,
 * Emma, Liam, Ava); admin (Ada) is *not* a member of the team space —
 * she lives on her own "Admin desk" space so /projects has content
 * whichever fixture user signs in. The team setup doubles as the
 * source the invite fixture attaches new signups to.
 */
class ProjectFixtures extends Fixture implements DependentFixtureInterface
{
    public const LAUNCH_SPACE_REFERENCE = 'space-launch';
    public const ENGINEERING_GROUP_REFERENCE = 'group-engineering';

    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var User $uma */
        $uma = $this->getReference(UserFixtures::USER_REFERENCE, User::class);
        /** @var User $ada */
        $ada = $this->getReference(UserFixtures::ADMIN_REFERENCE, User::class);

        // Tags are owned per-user; create them on Uma since she owns the
        // tasks we'll attach them to.
        $tagDefinitions = [
            'urgent' => '#dc2626',
            'design' => '#7c3aed',
            'backend' => '#0d9488',
            'docs' => '#f59e0b',
        ];
        $tags = [];
        foreach ($tagDefinitions as $title => $color) {
            $tag = new Tag();
            $tag->setOwner($uma);
            $tag->setTitle($title);
            $tag->setColor($color);
            $manager->persist($tag);
            $tags[$title] = $tag;
        }

        // Pull the team members in. Uma owns the space; Noah/Emma/Liam/Ava
        // are regular members. Admin (Ada) is intentionally excluded —
        // the "user team space" is for the user side of the demo data
        // and admin gets a separate space below so /projects still has
        // content for an admin sign-in.
        $teamUsers = [];
        foreach (UserFixtures::TEAM_USER_REFERENCES as $reference) {
            $teamUsers[$reference] = $this->getReference($reference, User::class);
        }

        $sharedSpace = (new Space())
            ->setName('Launch team')
            ->setCreatedBy($uma);
        $manager->persist($sharedSpace);
        $manager->flush();
        $this->addReference(self::LAUNCH_SPACE_REFERENCE, $sharedSpace);

        $manager->persist((new SpaceMembership())
            ->setSpace($sharedSpace)
            ->setUser($uma)
            ->setRole(Space::ROLE_ADMIN));
        foreach ($teamUsers as $member) {
            $manager->persist((new SpaceMembership())
                ->setSpace($sharedSpace)
                ->setUser($member)
                ->setRole(Space::ROLE_MEMBER));
        }

        // Standalone admin space so an admin sign-in still has /projects
        // content, without putting admin in the team space.
        $adminSpace = (new Space())
            ->setName('Admin desk')
            ->setCreatedBy($ada);
        $manager->persist($adminSpace);
        $manager->flush();
        $manager->persist((new SpaceMembership())
            ->setSpace($adminSpace)
            ->setUser($ada)
            ->setRole(Space::ROLE_ADMIN));

        $adminProject = new Project();
        $adminProject->setOwner($ada);
        $adminProject->setSpace($adminSpace);
        $adminProject->setTitle('Admin checklist');
        $adminProject->setDescription("Admin housekeeping tasks live here so /projects isn't empty after a fresh fixtures load.");
        $manager->persist($adminProject);

        // Engineering group: Uma owns, the four team members + Uma are
        // in it. Doubles as a target for the invite fixture's GroupInvite
        // attachment so the signup page renders the "GROUP · N ppl" row.
        $engineering = new UserGroup();
        $engineering->setOwner($uma);
        $engineering->setTitle('Engineering');
        $engineering->addMember($uma);
        foreach ($teamUsers as $member) {
            $engineering->addMember($member);
        }
        $manager->persist($engineering);
        $this->addReference(self::ENGINEERING_GROUP_REFERENCE, $engineering);

        $project = new Project();
        $project->setOwner($uma);
        $project->setSpace($sharedSpace);
        $project->setTitle('Launch checklist');
        $project->setDescription("Things to ship before the **soft launch**.\n\n- Marketing site\n- Onboarding flow\n- Billing");
        $manager->persist($project);

        // [title, description, tagTitles, assignees]. Mix of solo, joint,
        // and unassigned tasks so the avatar group, "Assigned to me"
        // filter, and "Assign" empty-state all show up. Assignees are
        // pulled from the team space; admin (Ada) deliberately isn't in
        // this space so she's not an assignee here either.
        $noah = $teamUsers['user-noah'];
        $emma = $teamUsers['user-emma'];
        $liam = $teamUsers['user-liam'];
        $taskDefinitions = [
            ['Wire up Stripe checkout', 'Hook the pricing page CTA to a Stripe-hosted checkout session.', ['urgent', 'backend'], [$uma]],
            ['Draft onboarding email', 'Three-step welcome series. Tone: friendly, no jargon.', ['docs'], [$emma]],
            ['Polish empty states', "Replace the placeholder copy on Projects, Tasks, and Tags with the new illustrations.", ['design'], [$uma, $noah]],
            ['Add password-reset rate limiting', 'Limit to 3 attempts per email per hour.', ['urgent', 'backend'], []],
            ['Write API auth docs', 'Cover login, refresh, and logout end-to-end.', ['docs'], [$liam]],
        ];

        $position = 0;
        foreach ($taskDefinitions as [$title, $description, $tagTitles, $assignees]) {
            $task = new Task();
            $task->setOwner($uma);
            $task->setProject($project);
            $task->setTitle($title);
            $task->setDescription($description);
            $task->setPosition($position++);
            foreach ($tagTitles as $tagTitle) {
                $task->addTag($tags[$tagTitle]);
            }
            foreach ($assignees as $assignee) {
                $task->addAssignee($assignee);
            }
            $manager->persist($task);
        }

        // One personal (project-less) task and one already-completed task so
        // both states are covered.
        $personal = new Task();
        $personal->setOwner($uma);
        $personal->setTitle('Plan team offsite');
        $personal->setDescription('Shortlist three venues and email Ada for input.');
        $personal->setPosition($position++);
        $personal->addAssignee($uma);
        $manager->persist($personal);

        $done = new Task();
        $done->setOwner($uma);
        $done->setProject($project);
        $done->setTitle('Pick a launch date');
        $done->setDescription('Locked in: **May 6**.');
        $done->setPosition($position++);
        $done->setCompletedOn(new \DateTimeImmutable('-2 days'));
        $done->addTag($tags['urgent']);
        $manager->persist($done);

        $manager->flush();
    }
}
