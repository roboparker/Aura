<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Expense;
use App\Entity\MediaObject;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Expenses (#650): members log their own engagement-scoped costs, billed
 * expenses are frozen, and invoice generation pulls the unbilled billable
 * pool (stamping billedAt; void releases it again).
 */
class ExpenseTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;
    private FilesystemOperator $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
        $this->storage = static::getContainer()->get('media.storage');

        $this->entityManager->createQuery('DELETE FROM App\Entity\Invoice')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Expense')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ExpenseCategory')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\TimeEntry')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\EngagementCategory')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Engagement')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testMemberLogsOwnExpenseAndBilledLockFreezesIt(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $spaceIri = '/spaces/' . $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $bpIri = $this->createEngagement($client, $spaceIri, $this->iri($clientRow));

        // A plain member logs their own expense; the spender is stamped
        // server-side and the currency defaults from the engagement.
        $client->loginUser($member);
        $expense = $client->request('POST', '/expenses', [
            'json' => [
                'space' => $spaceIri,
                'engagement' => $bpIri,
                'spentOn' => '2026-07-10',
                'category' => 'Travel',
                'description' => 'Taxi to the site',
                'amount' => 2500,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('/users/' . $member->getId(), $expense['user'] ?? null);
        $this->assertSame('USD', $expense['currency'] ?? null);
        $this->assertTrue($expense['billable'] ?? null);
        $expenseIri = $this->iri($expense);

        // The member can edit their own expense while unbilled.
        $client->request('PATCH', $expenseIri, [
            'json' => ['amount' => 3000],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        // Billed = frozen: editing or deleting 422s. (Stamp through the client
        // kernel's EM so the request sees the change.)
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $expenseId = $expense['id'];
        $this->assertIsString($expenseId);
        $entity = $em->getRepository(Expense::class)->find($expenseId);
        $this->assertInstanceOf(Expense::class, $entity);
        $entity->setBilledAt(new \DateTimeImmutable());
        $em->flush();

        $client->request('PATCH', $expenseIri, [
            'json' => ['amount' => 9999],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
        $client->request('DELETE', $expenseIri);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testBillableExpensesFlowOntoInvoicesAndVoidReleasesThem(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $bpIri = $this->createEngagement($client, $spaceIri, $this->iri($clientRow));

        $makeExpense = function (int $amount, bool $billable, string $description) use ($client, $spaceIri, $bpIri): array {
            $row = $client->request('POST', '/expenses', [
                'json' => [
                    'space' => $spaceIri,
                    'engagement' => $bpIri,
                    'spentOn' => '2026-07-10',
                    'category' => 'Travel',
                    'description' => $description,
                    'amount' => $amount,
                    'billable' => $billable,
                ],
                'headers' => ['Content-Type' => 'application/ld+json'],
            ])->toArray();
            $this->assertResponseStatusCodeSame(201);

            return $row;
        };
        $billableExpense = $makeExpense(2500, true, 'Taxi to the site');
        $makeExpense(1000, false, 'Team lunch'); // never invoiced

        // Preview lists the billable expense in its own section.
        $previewed = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['engagement' => $bpIri, 'preview' => true],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(200);
        $previewExpenses = $previewed['expenses'] ?? null;
        $this->assertIsArray($previewExpenses);
        $this->assertCount(1, $previewExpenses);
        $this->assertSame(2500, $previewed['subtotal'] ?? null);

        // Generation (no tracked time — an expense-only invoice is fine)
        // pulls the expense as a line item and stamps billedAt.
        $invoice = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['engagement' => $bpIri],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(1, $invoice['lineItemCount'] ?? null);
        $this->assertSame(2500, $invoice['subtotal'] ?? null);
        $invoiceIri = $invoice['@id'];
        $this->assertIsString($invoiceIri);

        $full = $client->request('GET', $invoiceIri)->toArray();
        $lines = $full['lineItems'] ?? null;
        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);
        $line = $lines[0];
        $this->assertIsArray($line);
        $this->assertSame('Expense — Travel: Taxi to the site', $line['description'] ?? null);

        $billedExpense = $client->request('GET', $this->iri($billableExpense))->toArray();
        $this->assertNotNull($billedExpense['billedAt'] ?? null);

        // Nothing left to bill: a second generation 422s.
        $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['engagement' => $bpIri],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        // Voiding the invoice releases the expense for re-billing.
        $client->request('POST', $invoiceIri . '/void', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $released = $client->request('GET', $this->iri($billableExpense))->toArray();
        $this->assertNull($released['billedAt'] ?? null);
    }

    public function testManagedCategoriesCurateThePickerAndLabelExpenses(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $spaceIri = '/spaces/' . $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $bpIri = $this->createEngagement($client, $spaceIri, $this->iri($clientRow));

        // Admin curates the list (one unit-priced, one plain).
        $mileage = $client->request('POST', '/expense_categories', [
            'json' => ['space' => $spaceIri, 'name' => 'Mileage', 'unitAmount' => 65],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $travel = $client->request('POST', '/expense_categories', [
            'json' => ['space' => $spaceIri, 'name' => 'Travel'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);

        // A plain member reads the picker but can't manage it.
        $client->loginUser($member);
        $list = $client->request('GET', '/expense_categories?space=' . $spaceIri)->toArray();
        $this->assertSame(2, $list['totalItems'] ?? null);
        $client->request('POST', '/expense_categories', [
            'json' => ['space' => $spaceIri, 'name' => 'Sneaky'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        // Logging with a managed category denormalises its name as the label.
        $expense = $client->request('POST', '/expenses', [
            'json' => [
                'space' => $spaceIri,
                'engagement' => $bpIri,
                'spentOn' => '2026-07-10',
                'expenseCategory' => $this->iri($travel),
                'amount' => 2500,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('Travel', $expense['category'] ?? null);
        $this->assertSame($this->iri($travel), $expense['expenseCategory'] ?? null);

        // Archiving removes a category from the (archived=false) picker.
        $client->loginUser($admin);
        $client->request('PATCH', $this->iri($mileage), [
            'json' => ['archived' => true],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $active = $client->request('GET', '/expense_categories?space=' . $spaceIri . '&archived=false')->toArray();
        $this->assertSame(1, $active['totalItems'] ?? null);
    }

    public function testReceiptDownloadGatedToSpenderAndTimeReaders(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $spaceIri = '/spaces/' . $space->getId();
        // Created before any HTTP request — the client kernel reboots detach
        // this EM's entities, and persisting a MediaObject with a detached
        // owner would trip Doctrine's new-entity-through-relationship check.
        $receipt = $this->createReceipt($member);

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $bpIri = $this->createEngagement($client, $spaceIri, $this->iri($clientRow));

        // The member attaches their uploaded receipt to a new expense.
        $client->loginUser($member);
        $client->request('POST', '/expenses', [
            'json' => [
                'space' => $spaceIri,
                'engagement' => $bpIri,
                'spentOn' => '2026-07-10',
                'category' => 'Travel',
                'amount' => 2500,
                'receipt' => '/media-objects/' . $receipt->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $downloadUrl = '/media-objects/' . $receipt->getId() . '/download';

        // Spender (owner) downloads their own receipt.
        $client->request('GET', $downloadUrl);
        $this->assertResponseIsSuccessful();

        // A space admin (not the uploader) can download it too.
        $client->loginUser($admin);
        $client->request('GET', $downloadUrl);
        $this->assertResponseIsSuccessful();

        // An outsider gets 404 — not 403, to avoid leaking existence.
        $outsider = $this->createUser('outsider@example.com');
        $client->loginUser($outsider);
        $client->request('GET', $downloadUrl);
        $this->assertResponseStatusCodeSame(404);
    }

    /** A stored receipt file + MediaObject row owned by $owner. */
    private function createReceipt(User $owner): MediaObject
    {
        $bytes = 'RECEIPT-IMAGE-BYTES';
        $path = 'attachments/expense-test-' . bin2hex(random_bytes(4)) . '-receipt.png';
        $this->storage->write($path, $bytes);

        $media = (new MediaObject())
            ->setOwner($owner)
            ->setKind(MediaObject::KIND_ATTACHMENT)
            ->setVariants(['original' => $path])
            ->setOriginalName('receipt.png')
            ->setMimeType('image/png')
            ->setByteSize(\strlen($bytes));
        $this->entityManager->persist($media);
        $this->entityManager->flush();

        return $media;
    }

    /**
     * Admin creates an engagement (for $clientIri) with one billable "Dev"
     * category — expenses only need the engagement itself.
     */
    private function createEngagement(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        string $spaceIri,
        string $clientIri,
    ): string {
        $row = $client->request('POST', '/engagements', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientIri,
                'name' => 'Acme Website',
                'currency' => 'USD',
                'categories' => [
                    ['name' => 'Dev', 'rateAmount' => 6000, 'position' => 0],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);

        return $this->iri($row);
    }

    /**
     * The `@id` of a decoded resource, narrowed to string for concatenation.
     *
     * @param array<int|string, mixed> $row
     */
    private function iri(array $row): string
    {
        $iri = $row['@id'] ?? null;
        $this->assertIsString($iri);

        return $iri;
    }

    private function createSharedSpace(User $admin, ?User $member = null): Space
    {
        $space = (new Space())->setName('Studio')->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $adminMembership = (new SpaceMembership())
            ->setUser($admin)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($adminMembership);
        $this->entityManager->persist($adminMembership);
        if (null !== $member) {
            $memberMembership = (new SpaceMembership())
                ->setUser($member)
                ->setRole(Space::ROLE_MEMBER);
            $space->addUserMembership($memberMembership);
            $this->entityManager->persist($memberMembership);
        }
        $this->entityManager->flush();

        return $space;
    }

    private function createUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
