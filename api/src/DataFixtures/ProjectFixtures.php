<?php

namespace App\DataFixtures;

use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Demo project with a handful of tasks and tags so the empty-state isn't the
 * first thing seen after a fresh fixtures load. Owned by Uma; Ada is also a
 * member so the admin login lands on a non-empty Projects page too.
 */
class ProjectFixtures extends Fixture implements DependentFixtureInterface
{
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

        $project = new Project();
        $project->setOwner($uma);
        $project->setTitle('Launch checklist');
        $project->setDescription("Things to ship before the **soft launch**.\n\n- Marketing site\n- Onboarding flow\n- Billing");
        $project->addMember($uma);
        $project->addMember($ada);
        $manager->persist($project);

        $taskDefinitions = [
            ['Wire up Stripe checkout', 'Hook the pricing page CTA to a Stripe-hosted checkout session.', ['urgent', 'backend']],
            ['Draft onboarding email', 'Three-step welcome series. Tone: friendly, no jargon.', ['docs']],
            ['Polish empty states', "Replace the placeholder copy on Projects, Tasks, and Tags with the new illustrations.", ['design']],
            ['Add password-reset rate limiting', 'Limit to 3 attempts per email per hour.', ['urgent', 'backend']],
            ['Write API auth docs', 'Cover login, refresh, and logout end-to-end.', ['docs']],
        ];

        $position = 0;
        foreach ($taskDefinitions as [$title, $description, $tagTitles]) {
            $task = new Task();
            $task->setOwner($uma);
            $task->setProject($project);
            $task->setTitle($title);
            $task->setDescription($description);
            $task->setPosition($position++);
            foreach ($tagTitles as $tagTitle) {
                $task->addTag($tags[$tagTitle]);
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
