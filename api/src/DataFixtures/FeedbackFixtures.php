<?php

namespace App\DataFixtures;

use App\Entity\Comment;
use App\Entity\Feedback;
use App\Entity\FeedbackVote;
use App\Entity\User;
use App\Repository\FeedbackRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Demo data for the in-app feedback board. Seeds a spread of bug reports
 * and feature requests across every workflow status, owned by the various
 * fixture users, with a realistic distribution of up/down votes and a few
 * comment threads — enough to exercise the board's sort-by-score,
 * status/type filters, "your vote" highlight, and comment counts after a
 * fresh `doctrine:fixtures:load`.
 *
 * Ticket numbers are pulled from the same `feedback_ticket_seq` the
 * {@see FeedbackOwnerProcessor} uses, so the sequence stays in step with
 * the seeded rows and the first ticket filed through the UI continues the
 * count instead of colliding.
 */
class FeedbackFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private FeedbackRepository $feedbackRepository,
    ) {
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $users = [
            'uma' => $this->getReference(UserFixtures::USER_REFERENCE, User::class),
            'ada' => $this->getReference(UserFixtures::ADMIN_REFERENCE, User::class),
            'noah' => $this->getReference('user-noah', User::class),
            'emma' => $this->getReference('user-emma', User::class),
            'liam' => $this->getReference('user-liam', User::class),
            'ava' => $this->getReference('user-ava', User::class),
        ];

        // Each ticket: owner key, type, status, title, description, the
        // voters (key => +1 | -1), and an optional comment thread
        // (list of [author key, body]).
        $tickets = [
            [
                'owner' => 'uma',
                'type' => Feedback::TYPE_FEATURE,
                'status' => Feedback::STATUS_PLANNED,
                'title' => 'Dark mode across the whole app',
                'description' => "The marketing site is dark but the app itself is light-only. "
                    . "A proper dark theme (respecting the OS preference) would make late-night "
                    . "sessions a lot easier on the eyes.",
                'votes' => ['noah' => 1, 'emma' => 1, 'liam' => 1, 'ava' => 1, 'ada' => 1],
                'comments' => [
                    ['ada', "Great call — this is on the roadmap for next quarter."],
                    ['noah', "+1, the task drawer especially is blinding at night."],
                ],
            ],
            [
                'owner' => 'noah',
                'type' => Feedback::TYPE_BUG,
                'status' => Feedback::STATUS_OPEN,
                'title' => 'Task drawer closes when I click the date picker',
                'description' => "Opening a task, then clicking the due-date field, sometimes closes "
                    . "the whole drawer instead of opening the calendar. Happens about half the time "
                    . "in Firefox.",
                'votes' => ['uma' => 1, 'emma' => 1, 'liam' => 1],
                'comments' => [
                    ['emma', "Reproduced on my end too — only when the drawer was just reopened."],
                ],
            ],
            [
                'owner' => 'emma',
                'type' => Feedback::TYPE_FEATURE,
                'status' => Feedback::STATUS_IN_PROGRESS,
                'title' => 'Keyboard shortcuts for navigation',
                'description' => "A ⌘K command palette exists for search, but moving between Tasks, "
                    . "Projects, and Pages still needs the mouse. Vim-style `g t` / `g p` jumps would "
                    . "be lovely.",
                'votes' => ['uma' => 1, 'noah' => 1, 'ava' => 1],
                'comments' => [],
            ],
            [
                'owner' => 'liam',
                'type' => Feedback::TYPE_FEATURE,
                'status' => Feedback::STATUS_SHIPPED,
                'title' => 'Export all space data as a single archive',
                'description' => "For backups and GDPR requests it would help to download everything "
                    . "in a space — projects, tasks, pages, discussions, attachments — as one zip.",
                'votes' => ['uma' => 1, 'emma' => 1, 'ada' => 1, 'ava' => 1],
                'comments' => [
                    ['ada', "Shipped! Space admins can now export from Space settings → Export."],
                ],
            ],
            [
                'owner' => 'ava',
                'type' => Feedback::TYPE_BUG,
                'status' => Feedback::STATUS_OPEN,
                'title' => 'Avatar initials are unreadable on light personal colors',
                'description' => "When a user picks a pale avatar color the white initials nearly "
                    . "disappear. The contrast check at signup doesn't seem to catch the lightest "
                    . "swatches.",
                'votes' => ['noah' => 1, 'liam' => -1],
                'comments' => [],
            ],
            [
                'owner' => 'uma',
                'type' => Feedback::TYPE_FEATURE,
                'status' => Feedback::STATUS_DECLINED,
                'title' => 'Built-in Pomodoro timer',
                'description' => "A focus timer that ticks down inside the app and auto-starts the "
                    . "next task. Would keep me off a second tab.",
                'votes' => ['emma' => 1, 'noah' => -1, 'liam' => -1, 'ada' => -1],
                'comments' => [
                    ['ada', "Declining for now — it's a bit outside the core scope, and there are "
                        . "great standalone tools for this. Revisit if demand grows."],
                ],
            ],
            [
                'owner' => 'ada',
                'type' => Feedback::TYPE_BUG,
                'status' => Feedback::STATUS_IN_PROGRESS,
                'title' => 'Mercure reconnect storm after the laptop wakes from sleep',
                'description' => "After closing the lid and reopening, the live-update connection "
                    . "hammers the server with reconnect attempts for a few seconds before settling. "
                    . "Should back off exponentially.",
                'votes' => ['uma' => 1, 'liam' => 1],
                'comments' => [],
            ],
            [
                'owner' => 'emma',
                'type' => Feedback::TYPE_FEATURE,
                'status' => Feedback::STATUS_OPEN,
                'title' => "Recurrence rule for \"every weekday\"",
                'description' => "Daily recurrence includes weekends. A one-click \"every weekday "
                    . "(Mon–Fri)\" option would save building a custom weekly rule with five days "
                    . "ticked.",
                'votes' => ['uma' => 1, 'noah' => 1, 'liam' => 1, 'ava' => 1],
                'comments' => [],
            ],
        ];

        foreach ($tickets as $spec) {
            $ticket = new Feedback();
            $ticket->setOwner($users[$spec['owner']]);
            $ticket->setType($spec['type']);
            $ticket->setStatus($spec['status']);
            $ticket->setTitle($spec['title']);
            $ticket->setDescription($spec['description']);
            $ticket->setTicketNumber($this->feedbackRepository->nextTicketNumber());
            $manager->persist($ticket);

            foreach ($spec['votes'] as $voterKey => $value) {
                $vote = (new FeedbackVote())
                    ->setFeedback($ticket)
                    ->setUser($users[$voterKey])
                    ->setValue($value);
                $manager->persist($vote);
            }

            foreach ($spec['comments'] as [$authorKey, $body]) {
                $comment = (new Comment())
                    ->setFeedback($ticket)
                    ->setAuthor($users[$authorKey])
                    ->setBody($body);
                $manager->persist($comment);
            }
        }

        $manager->flush();
    }
}
