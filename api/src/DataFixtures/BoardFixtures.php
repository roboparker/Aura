<?php

namespace App\DataFixtures;

use App\Entity\Board;
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
 * Demo board + spaces / groups for fixture-driven dev and E2E. Uma
 * owns the "Launch team" space along with four team members (Noah,
 * Emma, Liam, Ava). Admin (Ada) is *not* a member of the team space —
 * her data lives in {@see AdminDeskFixtures}. The team setup doubles as
 * the source the invite fixture attaches new signups to.
 */
class BoardFixtures extends Fixture implements DependentFixtureInterface
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

        // Pull the team members in. Uma owns the space; Noah/Emma/Liam/Ava
        // are regular members. Admin (Ada) is intentionally excluded —
        // the "user team space" is for the user side of the demo data
        // and admin gets a separate space below so /boards still has
        // content for an admin sign-in.
        $teamUsers = [];
        foreach (UserFixtures::TEAM_USER_REFERENCES as $reference) {
            $teamUsers[$reference] = $this->getReference($reference, User::class);
        }

        $sharedSpace = (new Space())
            ->setName('Launch team')
            ->setDescription(
                'Everything for the Spring 2026 product launch — campaign, web, '
                . 'PR, and content, in one shared room.',
            )
            ->setCreatedBy($uma);
        $manager->persist($sharedSpace);
        $manager->flush();
        $this->addReference(self::LAUNCH_SPACE_REFERENCE, $sharedSpace);

        // Tags are scoped to a space (#tags): create them in the launch space
        // whose tasks attach them. Owner = Uma (the space admin).
        $tagDefinitions = [
            'urgent' => '#b91c1c',
            'design' => '#6d28d9',
            'backend' => '#0f766e',
            'docs' => '#854d0e',
        ];
        $tags = [];
        foreach ($tagDefinitions as $title => $color) {
            $tag = new Tag();
            $tag->setOwner($uma);
            $tag->setSpace($sharedSpace);
            $tag->setTitle($title);
            $tag->setColor($color);
            $manager->persist($tag);
            $tags[$title] = $tag;
        }

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

        // Engineering group: owned by the shared space (#groups-space), with
        // the four team members + Uma in it — so every group member has access
        // to the shared space. Doubles as a target for the invite fixture's
        // GroupInvite so the signup page renders the "GROUP · N ppl" row.
        $engineering = new UserGroup();
        $engineering->setSpace($sharedSpace);
        $engineering->setTitle('Engineering');
        $engineering->setSlug('eng');
        $engineering->setColor('#0369a1');
        $engineering->addMember($uma);
        foreach ($teamUsers as $member) {
            $engineering->addMember($member);
        }
        $manager->persist($engineering);
        $this->addReference(self::ENGINEERING_GROUP_REFERENCE, $engineering);

        // A second group in the same shared space so /groups lists more than
        // one row.
        $noah = $teamUsers['user-noah'];
        $designCrew = new UserGroup();
        $designCrew->setSpace($sharedSpace);
        $designCrew->setTitle('Design crew');
        $designCrew->setSlug('design');
        $designCrew->setColor('#7e22ce');
        $designCrew->addMember($noah);
        $designCrew->addMember($uma);
        $designCrew->addMember($teamUsers['user-emma']);
        $manager->persist($designCrew);

        $board = new Board();
        $board->setOwner($uma);
        $board->setSpace($sharedSpace);
        $board->setTitle('Launch checklist');
        $board->setDescription("Things to ship before the **soft launch**.\n\n- Marketing site\n- Onboarding flow\n- Billing");
        $manager->persist($board);

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
            ['Polish empty states', "Replace the placeholder copy on Boards, Tasks, and Tags with the new illustrations.", ['design'], [$uma, $noah]],
            ['Add password-reset rate limiting', 'Limit to 3 attempts per email per hour.', ['urgent', 'backend'], []],
            ['Write API auth docs', 'Cover login, refresh, and logout end-to-end.', ['docs'], [$liam]],
        ];

        $position = 0;
        foreach ($taskDefinitions as [$title, $description, $tagTitles, $assignees]) {
            $task = new Task();
            $task->setOwner($uma);
            $task->setBoard($board);
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

        // One personal (board-less) task and one already-completed task so
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
        $done->setBoard($board);
        $done->setTitle('Pick a launch date');
        $done->setDescription('Locked in: **May 6**.');
        $done->setPosition($position++);
        $done->setCompletedOn(new \DateTimeImmutable('-2 days'));
        $done->addTag($tags['urgent']);
        $manager->persist($done);

        $manager->flush();
    }
}
