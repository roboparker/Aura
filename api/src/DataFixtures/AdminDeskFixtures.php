<?php

namespace App\DataFixtures;

use App\CustomField\CustomFieldKind;
use App\Entity\Board;
use App\Entity\Client;
use App\Entity\Comment;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Discussion;
use App\Entity\Engagement;
use App\Entity\EngagementCategory;
use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Entity\MediaObject;
use App\Entity\Page;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\TaskRelationship;
use App\Entity\TaskSection;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Admin (Ada) demo data — two spaces so an admin sign-in is interesting:
 *
 *  - **everything** — a fully-populated shared space with at least one of
 *    every content type: a board (with task sections, tasks — one recurring
 *    with a reminder, a custom field & value, a task relationship, and task
 *    comments), a page with a sub-page and a page comment, a discussion +
 *    comment, tags, groups, and the full billing chain (client → engagement
 *    with a category/rate → a tracked time entry → a draft invoice). It also
 *    holds a second, deliberately **empty board** for the empty-state UI.
 *  - **nothing** — a deliberately empty shared space, so the empty-space UI
 *    is reachable without deleting anything.
 *
 * Admin (Ada) is intentionally not a member of the user-side "Launch team"
 * space ({@see BoardFixtures}); her data lives here.
 */
class AdminDeskFixtures extends Fixture implements DependentFixtureInterface
{
    public const ADMIN_DESK_SPACE_REFERENCE = 'space-admin-desk';

    public function __construct(
        #[Autowire(service: 'media.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

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

        // --- everything: a fully-populated shared space ---
        $desk = (new Space())
            ->setName('everything')
            ->setDescription(
                'Has at least one of every content type — the space to open when '
                . 'you want to see a populated UI.',
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

        // Board.
        $board = (new Board())
            ->setOwner($ada)
            ->setSpace($desk)
            ->setTitle('Admin checklist')
            ->setDescription(
                "Recurring admin chores so /boards isn't empty on an admin "
                . "sign-in.\n\n- Rotate backups\n- Review access\n- Reconcile invoices",
            );
        $manager->persist($board);

        // Custom field on the board (text). setBoard() stamps the space and
        // attaches the field to the board's many-to-many.
        $field = (new CustomFieldDefinition())
            ->setBoard($board)
            ->setName('Owner team')
            ->setKind(CustomFieldKind::TEXT->value)
            ->setSubtype('text')
            ->setConfig(['multi' => false])
            ->setPosition(0);
        $manager->persist($field);

        // Task sections (board columns).
        $todo = (new TaskSection())->setBoard($board)->setTitle('To do')->setPosition(0);
        $doing = (new TaskSection())->setBoard($board)->setTitle('In progress')->setPosition(1);
        $manager->persist($todo);
        $manager->persist($doing);

        // Tasks — open (with a recurrence + reminder), a custom-field value, and a completed one.
        $t1 = (new Task())
            ->setOwner($ada)->setBoard($board)->setSection($doing)
            ->setTitle('Rotate nightly backups')
            ->setDescription('Confirm the pg_dump + media tarball ran and prune to the newest 5.')
            ->setDueDate(new \DateTimeImmutable('+2 days'))
            ->setRecurrenceRule(['frequency' => 'weekly', 'interval' => 1])
            ->setReminders([['type' => 'relative', 'value' => 15, 'unit' => 'minutes', 'repeat' => false]])
            ->setPosition(0);
        $t1->addTag($tags['ops']);
        $t1->addAssignee($ada);
        $t1->addCustomFieldValue((new CustomFieldValue())->setDefinition($field)->setValue('Platform'));
        $manager->persist($t1);

        $t2 = (new Task())
            ->setOwner($ada)->setBoard($board)->setSection($todo)
            ->setTitle('Reconcile March invoices')
            ->setDescription('Match Stripe payouts against the ledger.')
            ->setPosition(1);
        $t2->addTag($tags['finance']);
        $manager->persist($t2);

        $t3 = (new Task())
            ->setOwner($ada)->setBoard($board)
            ->setTitle('Review who has admin')
            ->setDescription('Quarterly access review — drop anyone who no longer needs it.')
            ->setPosition(2)
            ->setCompletedOn(new \DateTimeImmutable('-3 days'));
        $manager->persist($t3);

        // Task relationship + a comment on a task.
        $manager->persist(
            (new TaskRelationship())->setSource($t1)->setTarget($t2)->setType(TaskRelationship::TYPE_RELATED),
        );
        $manager->persist(
            (new Comment())->setTask($t1)->setAuthor($noah)
                ->setBody('Backups looked good last night — pruned to 5.'),
        );

        // Pages: a small nested tree (three levels, with siblings) so the
        // sidebar Pages navigator has real depth to show off — expand/collapse,
        // per-level indentation, and drag-to-nest.
        $runbook = (new Page())
            ->setSpace($desk)->setCreatedBy($ada)
            ->setTitle('Ops runbook')
            ->setBody("# Ops runbook\n\nHow to keep the lights on.\n\n## Backups\nNightly at 02:00 UTC.")
            ->setPosition(0);
        $manager->persist($runbook);

        $handbook = (new Page())
            ->setSpace($desk)->setCreatedBy($ada)
            ->setTitle('Engineering handbook')
            ->setBody("# Engineering handbook\n\nHow we build and ship.")
            ->setPosition(1);
        $manager->persist($handbook);
        $manager->flush(); // top-level pages need ids before their children link

        $restoring = (new Page())
            ->setSpace($desk)->setCreatedBy($ada)->setParent($runbook)
            ->setTitle('Restoring from a backup')
            ->setBody('Steps to restore the latest pg_dump + media tarball.')
            ->setPosition(0);
        $manager->persist($restoring);

        $rotation = (new Page())
            ->setSpace($desk)->setCreatedBy($ada)->setParent($runbook)
            ->setTitle('Backup rotation policy')
            ->setBody('Keep the newest five nightly archives; prune the rest.')
            ->setPosition(1);
        $manager->persist($rotation);

        $manager->persist((new Page())
            ->setSpace($desk)->setCreatedBy($ada)->setParent($handbook)
            ->setTitle('Onboarding')
            ->setBody('Day-one setup: accounts, repos, and the local stack.')
            ->setPosition(0));
        $manager->persist((new Page())
            ->setSpace($desk)->setCreatedBy($ada)->setParent($handbook)
            ->setTitle('Release process')
            ->setBody('Cut a tag, let CI build, then promote main → production.')
            ->setPosition(1));
        $manager->flush(); // second level needs ids before the grandchild links

        // A third level, so the tree shows more than one level of nesting.
        $manager->persist((new Page())
            ->setSpace($desk)->setCreatedBy($ada)->setParent($restoring)
            ->setTitle('Verifying a restore')
            ->setBody('Smoke-test checklist to run once a restore completes.')
            ->setPosition(0));

        // Comment on a page.
        $manager->persist(
            (new Comment())->setPage($runbook)->setAuthor($emma)
                ->setBody('Added the S3 lifecycle note under Backups.'),
        );

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

        // Billing chain: client → engagement (+ category/rate) → tracked time → a draft invoice.
        $client = (new Client())
            ->setSpace($desk)->setCreatedBy($ada)
            ->setName('Acme Co')->setEmail('billing@acme.example')->setCurrency('USD')
            ->setDefaultRateAmount(12000);
        $manager->persist($client);

        $devCategory = (new EngagementCategory())->setName('Development')->setRateAmount(12000)->setPosition(0);
        $engagement = (new Engagement())
            ->setSpace($desk)->setClient($client)->setCreatedBy($ada)
            ->setName('Acme website')->setCurrency('USD')
            ->setDescription(
                "## Scope\n\nBuild + launch the Acme marketing site.\n\n"
                . "- Net-30 terms\n- Weekly status call\n- Contract on file in the Files tab",
            );
        $engagement->addCategory($devCategory);
        $manager->persist($engagement);
        // Roll the admin board up to this engagement.
        $board->setEngagement($engagement);

        // A fake signed contract file attached to the engagement (Files tab).
        $contractBody = "ACME MASTER SERVICES AGREEMENT\n\n"
            . "This sample agreement is seeded so the engagement's contract files "
            . "have something to show.\n\n- Term: 12 months\n- Billing: Net-30\n"
            . "- Signed: Ada Admin\n";
        $contractPath = 'attachments/fixture-acme-contract.txt';
        $this->storage->write($contractPath, $contractBody);
        $contract = (new MediaObject())
            ->setOwner($ada)
            ->setKind(MediaObject::KIND_ATTACHMENT)
            ->setVariants(['original' => $contractPath])
            ->setOriginalName('acme-contract.txt')
            ->setMimeType('text/plain')
            ->setByteSize(\strlen($contractBody));
        $manager->persist($contract);
        $engagement->addAttachment($contract);

        $entry = (new TimeEntry())
            ->setSpace($desk)->setUser($ada)
            ->setEngagement($engagement)->setCategory($devCategory)
            ->setDescription('Set up the staging environment.')
            ->setStartedAt(new \DateTimeImmutable('-2 days 09:00'))
            ->setEndedAt(new \DateTimeImmutable('-2 days 11:00'));
        $manager->persist($entry);

        // A draft invoice for the client (2h × $120 = $240).
        $invoice = (new Invoice())
            ->setSpace($desk)->setClient($client)->setCreatedBy($ada)
            ->setCurrency('USD')->setStatus(Invoice::STATUS_DRAFT)
            ->setSubtotal(24000)->setTaxAmount(0)->setTotal(24000);
        $invoice->addLineItem(
            (new InvoiceLineItem())
                ->setDescription('Development — Set up the staging environment.')
                ->setQuantity(2.0)->setUnitAmount(12000)->setAmount(24000)->setPosition(0),
        );
        $manager->persist($invoice);

        // A second, deliberately empty board — for exercising the empty-state UI.
        $emptyBoard = (new Board())
            ->setOwner($ada)->setSpace($desk)
            ->setTitle('Empty board')
            ->setDescription('Nothing here yet — handy for testing the empty board state.');
        $manager->persist($emptyBoard);

        // --- nothing: an intentionally empty shared space ---
        $sandbox = (new Space())
            ->setName('nothing')
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
