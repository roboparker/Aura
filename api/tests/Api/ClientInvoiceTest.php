<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Invoice;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Tests\Billing\InMemoryStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Invoicing (#445): clients + invoices are gated on the admin-reserved
 * `invoices` category (only space admins or members granted an invoicing role),
 * and invoice totals are derived server-side from the line items.
 */
class ClientInvoiceTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Invoice')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\TimeEntry')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Service')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAdminCreatesClientAndInvoiceWithDerivedTotals(): void
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
        $this->assertResponseStatusCodeSame(201);
        $clientIri = $clientRow['@id'];
        $this->assertIsString($clientIri);

        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientIri,
                'currency' => 'USD',
                'taxRate' => 1000, // 10%
                'lineItems' => [
                    ['description' => 'Design', 'quantity' => 2, 'unitAmount' => 5000, 'position' => 0],
                    ['description' => 'Build', 'quantity' => 1.5, 'unitAmount' => 8000, 'position' => 1],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);

        // 2×5000 + 1.5×8000 = 22000; tax 10% = 2200; total 24200 — all derived server-side.
        $this->assertSame(22000, $invoice['subtotal'] ?? null);
        $this->assertSame(2200, $invoice['taxAmount'] ?? null);
        $this->assertSame(24200, $invoice['total'] ?? null);
        $this->assertSame(Invoice::STATUS_DRAFT, $invoice['status'] ?? null);
        $lineItems = $invoice['lineItems'] ?? [];
        $this->assertIsArray($lineItems);
        $this->assertCount(2, $lineItems);
    }

    public function testMemberWithoutInvoiceRoleCannotCreateClient(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $spaceIri = '/spaces/' . $space->getId();

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Sneaky Co'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        // invoices is admin-reserved: a plain member has no explicit grant.
        $this->assertResponseStatusCodeSame(403);
    }

    public function testMemberCannotSeeInvoicesInCollection(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $spaceIri = '/spaces/' . $space->getId();

        // Admin creates a client + invoice.
        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'lineItems' => [['description' => 'Work', 'quantity' => 1, 'unitAmount' => 1000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // The member (no invoicing grant) sees an empty invoice list.
        $client->loginUser($member);
        $list = $client->request('GET', '/invoices?space=' . $spaceIri)->toArray();
        $this->assertSame(0, $list['totalItems'] ?? null);
    }

    public function testGenerateInvoiceFromTrackedTime(): void
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
        [$bpIri, $devCat, $buildCat, $internalCat] = $this->createProject($client, $spaceIri, $this->iri($clientRow));

        // Two billable completed entries (distinct category rates) + one on the
        // non-billable "Internal" category (excluded from the invoice).
        $this->trackTime($client, $spaceIri, $bpIri, $devCat, '2026-07-03T09:00:00+00:00', '2026-07-03T10:00:00+00:00');
        $this->trackTime($client, $spaceIri, $bpIri, $buildCat, '2026-07-03T11:00:00+00:00', '2026-07-03T11:30:00+00:00');
        $this->trackTime($client, $spaceIri, $bpIri, $internalCat, '2026-07-03T12:00:00+00:00', '2026-07-03T13:00:00+00:00');

        $response = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpIri],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $result = $response->toArray();

        // 1h×6000 + 0.5h×8000 = 10000; the non-billable entry is excluded.
        $this->assertSame(2, $result['lineItemCount'] ?? null);
        $this->assertSame(10000, $result['subtotal'] ?? null);

        // The pulled entries are now billed, so a second run has nothing to bill.
        $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpIri],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testInvoicePreviewListsCandidatesWithoutBilling(): void
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
        [$bpIri, $devCat] = $this->createProject($client, $spaceIri, $this->iri($clientRow));

        $this->trackTime($client, $spaceIri, $bpIri, $devCat, '2026-07-03T09:00:00+00:00', '2026-07-03T10:00:00+00:00');
        $this->trackTime($client, $spaceIri, $bpIri, $devCat, '2026-07-04T09:00:00+00:00', '2026-07-04T09:30:00+00:00');

        $preview = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpIri, 'preview' => true],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame(2, $preview['count'] ?? null);
        // 1h × 6000 + 0.5h × 6000 = 9000.
        $this->assertSame(9000, $preview['subtotal'] ?? null);

        // Preview stamped nothing — a real generation afterwards still pulls both.
        $result = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpIri],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(2, $result['lineItemCount'] ?? null);
    }

    public function testInvoiceGenerationHonoursRangeAndSelection(): void
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
        [$bpIri, $devCat] = $this->createProject($client, $spaceIri, $this->iri($clientRow));

        // One hour on each of July 1, 2 and 3.
        foreach (['01', '02', '03'] as $day) {
            $this->trackTime(
                $client,
                $spaceIri,
                $bpIri,
                $devCat,
                sprintf('2026-07-%sT09:00:00+00:00', $day),
                sprintf('2026-07-%sT10:00:00+00:00', $day),
            );
        }

        // A range preview scopes the pool (July 2 only) and yields the entry id.
        $preview = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpIri, 'from' => '2026-07-02', 'to' => '2026-07-02', 'preview' => true],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $preview['count'] ?? null);
        $rows = $preview['entries'];
        $this->assertIsArray($rows);
        $first = $rows[0];
        $this->assertIsArray($first);
        $july2Iri = $first['@id'];
        $this->assertIsString($july2Iri);

        // Generate July 1–2 but explicitly select only the July 2 entry.
        $result = $client->request('POST', '/invoices/from-time-entries', [
            'json' => [
                'project' => $bpIri,
                'from' => '2026-07-01',
                'to' => '2026-07-02',
                'entries' => [$july2Iri],
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(1, $result['lineItemCount'] ?? null);
        $this->assertSame(6000, $result['subtotal'] ?? null);

        // July 1 and July 3 stay invoiceable for a later invoice.
        $rest = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpIri, 'preview' => true],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertSame(2, $rest['count'] ?? null);
    }

    public function testInvoiceSelectionRejectsNonInvoiceableEntry(): void
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
        $clientIri = $this->iri($clientRow);
        [$bpA] = $this->createProject($client, $spaceIri, $clientIri);
        [$bpB, $devB] = $this->createProject($client, $spaceIri, $clientIri);

        $this->trackTime($client, $spaceIri, $bpB, $devB, '2026-07-03T09:00:00+00:00', '2026-07-03T10:00:00+00:00');
        $previewB = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpB, 'preview' => true],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $rowsB = $previewB['entries'];
        $this->assertIsArray($rowsB);
        $firstB = $rowsB[0];
        $this->assertIsArray($firstB);
        $entryBIri = $firstB['@id'];
        $this->assertIsString($entryBIri);

        // Selecting project B's entry while invoicing project A → 422.
        $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpA, 'entries' => [$entryBIri]],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        // Malformed dates are rejected too.
        $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpB, 'from' => 'not-a-date'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testIssueAssignsNumberThenMarkPaid(): void
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
        [$bpIri, $devCat] = $this->createProject($client, $spaceIri, $this->iri($clientRow));
        $this->trackTime($client, $spaceIri, $bpIri, $devCat, '2026-07-03T09:00:00+00:00', '2026-07-03T10:00:00+00:00');
        $invoice = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpIri],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $invoiceIri = $invoice['@id'];
        $this->assertIsString($invoiceIri);

        // Issue → first per-space number + sent status.
        $issued = $client->request('POST', $invoiceIri . '/issue', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame('INV-0001', $issued['number'] ?? null);
        $this->assertSame('sent', $issued['status'] ?? null);

        // Re-issuing a non-draft is rejected.
        $client->request('POST', $invoiceIri . '/issue', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);

        // Mark paid.
        $paid = $client->request('POST', $invoiceIri . '/mark-paid', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertSame('paid', $paid['status'] ?? null);
        $this->assertNotNull($paid['paidAt'] ?? null);
    }

    public function testVoidReleasesBilledTime(): void
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
        [$bpIri, $devCat] = $this->createProject($client, $spaceIri, $this->iri($clientRow));
        $this->trackTime($client, $spaceIri, $bpIri, $devCat, '2026-07-03T09:00:00+00:00', '2026-07-03T10:00:00+00:00');

        $invoice = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpIri],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();

        // Voiding releases the billed entry, so it can be re-invoiced.
        $client->request('POST', $this->iri($invoice) . '/void', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpIri],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testSendMintsPublicLinkAndPublicViewWorks(): void
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
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'lineItems' => [['description' => 'Work', 'quantity' => 2, 'unitAmount' => 5000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $sent = $client->request('POST', $this->iri($invoice) . '/send', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('INV-0001', $sent['number'] ?? null);
        $token = $sent['token'] ?? null;
        $this->assertIsString($token);

        // The public token view resolves the invoice (no auth required by route).
        $public = $client->request('GET', '/public/invoices/' . $token)->toArray();
        $this->assertSame('INV-0001', $public['number'] ?? null);
        $this->assertSame(10000, $public['total'] ?? null);
        $this->assertSame('Acme Co', $public['billTo'] ?? null);

        // An unknown token 404s.
        $client->request('GET', '/public/invoices/deadbeef');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testSendEmailsTheClient(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'email' => 'billing@acme.test', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'lineItems' => [['description' => 'Work', 'quantity' => 1, 'unitAmount' => 5000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $client->request('POST', $this->iri($invoice) . '/send', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertEmailCount(1);
        $message = $this->getMailerMessage();
        $this->assertNotNull($message);
        $this->assertEmailAddressContains($message, 'To', 'billing@acme.test');
    }

    public function testOverdueSweepFlipsPastDueSentInvoices(): void
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
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'dueDate' => '2020-01-01',
                'lineItems' => [['description' => 'Work', 'quantity' => 1, 'unitAmount' => 5000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $invoiceIri = $invoice['@id'];
        $this->assertIsString($invoiceIri);

        // Send it so it's "sent" (and past-due).
        $client->request('POST', $invoiceIri . '/send', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Run the daily overdue sweep.
        $repo = $this->entityManager->getRepository(Invoice::class);
        $changed = $repo->markOverdue(new \DateTimeImmutable('today'));
        $this->assertSame(1, $changed);

        $refreshed = $client->request('GET', $invoiceIri)->toArray();
        $this->assertSame('overdue', $refreshed['status'] ?? null);
    }

    public function testLatePaymentRemindersFollowScheduleAndOptOut(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'email' => 'billing@acme.test', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
        $makeInvoice = function (string $description) use ($client, $spaceIri, $clientRow, $yesterday): array {
            $row = $client->request('POST', '/invoices', [
                'json' => [
                    'space' => $spaceIri,
                    'client' => $clientRow['@id'],
                    'currency' => 'USD',
                    'dueDate' => $yesterday,
                    'lineItems' => [['description' => $description, 'quantity' => 1, 'unitAmount' => 5000]],
                ],
                'headers' => ['Content-Type' => 'application/ld+json'],
            ])->toArray();
            $this->assertResponseStatusCodeSame(201);
            $client->request('POST', $this->iri($row) . '/send', [
                'json' => [],
                'headers' => ['Content-Type' => 'application/json'],
            ]);
            $this->assertResponseStatusCodeSame(201);

            return $row;
        };

        // A: reminders on (default). B: paused via the per-invoice opt-out.
        $a = $makeInvoice('Work A');
        $b = $makeInvoice('Work B');
        $client->request('PATCH', $this->iri($b), [
            'json' => ['remindersEnabled' => false],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        // No more HTTP requests from here — each client request reboots the
        // kernel and resets the mailer message logger, so the counts below see
        // only what the in-process sweep sends.
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $handler = static::getContainer()->get(\App\MessageHandler\MarkOverdueInvoicesHandler::class);

        // The daily sweep flips both overdue and sends A's first reminder only
        // (B is paused).
        $handler(new \App\Message\MarkOverdueInvoices());
        $this->assertEmailCount(1);
        $message = $this->getMailerMessage();
        $this->assertNotNull($message);
        $this->assertEmailAddressContains($message, 'To', 'billing@acme.test');

        // Re-run the same day: reminder 2 isn't due yet (+7d), nothing new sent.
        $handler(new \App\Message\MarkOverdueInvoices());
        $this->assertEmailCount(1);

        $aId = $a['id'];
        $this->assertIsString($aId);
        $aEntity = $em->getRepository(Invoice::class)->find($aId);
        $this->assertInstanceOf(Invoice::class, $aEntity);
        $this->assertCount(1, $aEntity->getRemindersSentAt());
        $bId = $b['id'];
        $this->assertIsString($bId);
        $bEntity = $em->getRepository(Invoice::class)->find($bId);
        $this->assertInstanceOf(Invoice::class, $bEntity);
        $this->assertSame([], $bEntity->getRemindersSentAt());

        // A missed week self-heals: back-date A's due date so +7d has passed —
        // the next run sends exactly one more (reminder 2 of 3).
        $aEntity->setDueDate(new \DateTimeImmutable('-8 days'));
        $em->flush();

        $handler(new \App\Message\MarkOverdueInvoices());
        $this->assertEmailCount(2);
        $em->refresh($aEntity);
        $this->assertCount(2, $aEntity->getRemindersSentAt());
    }

    public function testClientCanBeEdited(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $row = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $updated = $client->request('PATCH', $this->iri($row), [
            'json' => [
                'name' => 'Acme Corporation',
                'email' => 'billing@acme.test',
                'address' => "1 Loop\nCupertino",
                'currency' => 'EUR',
                'defaultRateAmount' => 15000,
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray();
        $this->assertResponseIsSuccessful();
        $this->assertSame('Acme Corporation', $updated['name'] ?? null);
        $this->assertSame('billing@acme.test', $updated['email'] ?? null);
        $this->assertSame('EUR', $updated['currency'] ?? null);
        $this->assertSame(15000, $updated['defaultRateAmount'] ?? null);
    }

    public function testRecurringInvoiceSpawnsAFreshDraft(): void
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
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'recurrenceFrequency' => 'monthly',
                'recurrenceInterval' => 1,
                'nextIssueDate' => '2020-01-01',
                'lineItems' => [['description' => 'Retainer', 'quantity' => 1, 'unitAmount' => 20000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('monthly', $invoice['recurrenceFrequency'] ?? null);

        // Run the recurring spawn.
        $spawner = static::getContainer()->get(\App\Service\RecurringInvoiceSpawner::class);
        $spawned = $spawner->spawnDue(new \DateTimeImmutable('today'));
        $this->assertSame(1, $spawned);

        // Now two invoices: the template + the fresh draft clone.
        $list = $client->request('GET', '/invoices?space=' . $spaceIri)->toArray();
        $this->assertSame(2, $list['totalItems'] ?? null);
    }

    public function testRecurringInvoiceStopsAfterCount(): void
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
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'recurrenceFrequency' => 'monthly',
                'recurrenceInterval' => 1,
                'nextIssueDate' => '2020-01-01',
                'recurrenceCount' => 1,
                'lineItems' => [['description' => 'Retainer', 'quantity' => 1, 'unitAmount' => 20000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $invoiceIri = $this->iri($invoice);

        $spawner = static::getContainer()->get(\App\Service\RecurringInvoiceSpawner::class);
        // First run spawns the one allowed occurrence and ends the recurrence.
        $this->assertSame(1, $spawner->spawnDue(new \DateTimeImmutable('today')));
        // Second run finds nothing — the template is no longer recurring.
        $this->assertSame(0, $spawner->spawnDue(new \DateTimeImmutable('today')));

        // Recurrence ended: the now-null fields are skipped from the read payload.
        $template = $client->request('GET', $invoiceIri)->toArray();
        $this->assertArrayNotHasKey('recurrenceFrequency', $template);
        $this->assertArrayNotHasKey('recurrenceCount', $template);
        $this->assertArrayNotHasKey('nextIssueDate', $template);

        // Exactly one clone was produced (template + one draft).
        $list = $client->request('GET', '/invoices?space=' . $spaceIri)->toArray();
        $this->assertSame(2, $list['totalItems'] ?? null);
    }

    public function testRecurringInvoiceStopsAtEndDate(): void
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
        // Next issue 2020-01-01, ends 2020-01-15: after one monthly step the next
        // occurrence (2020-02-01) falls past the end date, so recurrence stops.
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'recurrenceFrequency' => 'monthly',
                'recurrenceInterval' => 1,
                'nextIssueDate' => '2020-01-01',
                'recurrenceEndDate' => '2020-01-15',
                'lineItems' => [['description' => 'Retainer', 'quantity' => 1, 'unitAmount' => 20000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $invoiceIri = $this->iri($invoice);

        $spawner = static::getContainer()->get(\App\Service\RecurringInvoiceSpawner::class);
        $this->assertSame(1, $spawner->spawnDue(new \DateTimeImmutable('today')));
        $this->assertSame(0, $spawner->spawnDue(new \DateTimeImmutable('today')));

        // Recurrence ended: the now-null fields are skipped from the read payload.
        $template = $client->request('GET', $invoiceIri)->toArray();
        $this->assertArrayNotHasKey('recurrenceFrequency', $template);
        $this->assertArrayNotHasKey('recurrenceEndDate', $template);
    }

    public function testRecurringInvoiceRejectsTwoEndConditions(): void
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
        $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'recurrenceFrequency' => 'monthly',
                'recurrenceInterval' => 1,
                'nextIssueDate' => '2020-01-01',
                'recurrenceEndDate' => '2020-06-01',
                'recurrenceCount' => 3,
                'lineItems' => [['description' => 'Retainer', 'quantity' => 1, 'unitAmount' => 20000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testInvoicePdfRenders(): void
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
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'lineItems' => [['description' => 'Design work', 'quantity' => 2, 'unitAmount' => 5000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();

        $response = $client->request('GET', $this->iri($invoice) . '/pdf');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/pdf');
        // A real PDF starts with the %PDF- magic bytes.
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function testPublicPayStartsCheckoutThenWebhookMarksPaid(): void
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
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'lineItems' => [['description' => 'Work', 'quantity' => 2, 'unitAmount' => 5000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $invoiceIri = $invoice['@id'];
        $this->assertIsString($invoiceIri);
        $invoiceId = substr($invoiceIri, (int) strrpos($invoiceIri, '/') + 1);

        $sent = $client->request('POST', $invoiceIri . '/send', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $token = $sent['token'];
        $this->assertIsString($token);

        // The public pay endpoint starts a Stripe checkout (in-memory in tests).
        $pay = $client->request('POST', '/public/invoices/' . $token . '/pay', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('stripe', $pay['provider'] ?? null);
        $this->assertSame(InMemoryStripeGateway::PAYMENT_URL, $pay['url'] ?? null);

        // The provider's completed-checkout webhook marks the invoice paid.
        $event = [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'payment_status' => 'paid',
                'metadata' => ['invoice_id' => $invoiceId],
            ]],
        ];
        $client->request('POST', '/billing/webhook', [
            'body' => json_encode($event),
            'headers' => [
                'Stripe-Signature' => InMemoryStripeGateway::VALID_SIGNATURE,
                'Content-Type' => 'application/json',
            ],
        ]);
        $this->assertResponseIsSuccessful();

        $refreshed = $client->request('GET', $invoiceIri)->toArray();
        $this->assertSame('paid', $refreshed['status'] ?? null);
        $this->assertNotNull($refreshed['paidAt'] ?? null);
    }

    public function testInvoiceBrandingNumbersLogoAndTerms(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceId = (string) $space->getId();
        $spaceIri = '/spaces/' . $spaceId;

        // Media rows created before the HTTP phase — the client kernel
        // reboots detach this EM's entities.
        $storage = static::getContainer()->get('media.storage');
        $logoPath = 'avatars/branding-test-' . bin2hex(random_bytes(4)) . '.png';
        $storage->write($logoPath, 'LOGO-PNG-BYTES');
        $logo = (new \App\Entity\MediaObject())
            ->setOwner($admin)
            ->setKind(\App\Entity\MediaObject::KIND_AVATAR)
            ->setVariants(['profile' => $logoPath])
            ->setOriginalName('logo.png')
            ->setMimeType('image/png')
            ->setByteSize(14);
        $this->entityManager->persist($logo);
        $doc = (new \App\Entity\MediaObject())
            ->setOwner($admin)
            ->setKind(\App\Entity\MediaObject::KIND_ATTACHMENT)
            ->setVariants(['original' => $logoPath])
            ->setOriginalName('doc.pdf')
            ->setMimeType('application/pdf')
            ->setByteSize(1);
        $this->entityManager->persist($doc);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);

        // Admin configures branding on the space.
        $client->request('PATCH', $spaceIri, [
            'json' => [
                'invoiceNumberPrefix' => 'ACME-',
                'invoiceNumberNext' => 42,
                'invoiceTerms' => 'Net 30. Thank you!',
                'invoiceLogo' => '/media-objects/' . $logo->getId(),
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        // An attachment-kind logo is rejected (must be an uploaded image).
        $client->request('PATCH', $spaceIri, [
            'json' => ['invoiceLogo' => '/media-objects/' . $doc->getId()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $makeInvoice = function () use ($client, $spaceIri, $clientRow): array {
            $row = $client->request('POST', '/invoices', [
                'json' => [
                    'space' => $spaceIri,
                    'client' => $clientRow['@id'],
                    'currency' => 'USD',
                    'lineItems' => [['description' => 'Work', 'quantity' => 1, 'unitAmount' => 5000]],
                ],
                'headers' => ['Content-Type' => 'application/ld+json'],
            ])->toArray();
            $this->assertResponseStatusCodeSame(201);

            return $row;
        };

        // Numbering honours the prefix + explicit counter, and advances.
        $first = $makeInvoice();
        $issued = $client->request('POST', $this->iri($first) . '/issue', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertSame('ACME-0042', $issued['number'] ?? null);

        $second = $makeInvoice();
        $sent = $client->request('POST', $this->iri($second) . '/send', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('ACME-0043', $sent['number'] ?? null);
        $token = $sent['token'];
        $this->assertIsString($token);

        // The public page carries the logo URL + terms.
        $publicView = $client->request('GET', '/public/invoices/' . $token)->toArray();
        $this->assertSame('/media/' . $logoPath, $publicView['logoUrl'] ?? null);
        $this->assertSame('Net 30. Thank you!', $publicView['terms'] ?? null);

        // The PDF renders with the branding embedded.
        $pdf = $client->request('GET', '/public/invoices/' . $token . '/pdf');
        $this->assertResponseStatusCodeSame(200);
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function testPerLineTaxRatesOverrideAndBreakDown(): void
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

        // Invoice-level 10%; line B overrides to 20%, line C is tax-free.
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'taxRate' => 1000,
                'lineItems' => [
                    ['description' => 'A', 'quantity' => 1, 'unitAmount' => 10000, 'position' => 0],
                    ['description' => 'B', 'quantity' => 1, 'unitAmount' => 20000, 'taxRate' => 2000, 'position' => 1],
                    ['description' => 'C', 'quantity' => 1, 'unitAmount' => 5000, 'taxRate' => 0, 'position' => 2],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(35000, $invoice['subtotal'] ?? null);
        // 10% of 10000 + 20% of 20000 + 0 = 5000.
        $this->assertSame(5000, $invoice['taxAmount'] ?? null);
        $this->assertSame(40000, $invoice['total'] ?? null);
        $this->assertSame(
            [['rate' => 1000, 'amount' => 1000], ['rate' => 2000, 'amount' => 4000]],
            $invoice['taxBreakdown'] ?? null,
        );

        // A 50% discount halves each line's taxable share proportionally.
        $patched = $client->request('PATCH', $this->iri($invoice), [
            'json' => ['discountType' => 'percent', 'discountValue' => 5000],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(17500, $patched['discountAmount'] ?? null);
        $this->assertSame(2500, $patched['taxAmount'] ?? null);
        $this->assertSame(20000, $patched['total'] ?? null);

        // A line rate outside 0–1000% is rejected.
        $client->request('PATCH', $this->iri($invoice), [
            'json' => [
                'lineItems' => [
                    ['description' => 'A', 'quantity' => 1, 'unitAmount' => 10000, 'taxRate' => 200000, 'position' => 0],
                ],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testPdfEmbedsImageReceiptsFromExpenseLines(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);
        $spaceIri = '/spaces/' . $space->getId();

        // A real (tiny) PNG receipt, created before the HTTP phase.
        $image = imagecreatetruecolor(8, 8);
        self::assertNotFalse($image);
        ob_start();
        imagepng($image);
        $pngBytes = (string) ob_get_clean();
        imagedestroy($image);
        $storage = static::getContainer()->get('media.storage');
        $receiptPath = 'attachments/pdf-receipt-' . bin2hex(random_bytes(4)) . '.png';
        $storage->write($receiptPath, $pngBytes);
        $receipt = (new \App\Entity\MediaObject())
            ->setOwner($admin)
            ->setKind(\App\Entity\MediaObject::KIND_ATTACHMENT)
            ->setVariants(['original' => $receiptPath])
            ->setOriginalName('receipt.png')
            ->setMimeType('image/png')
            ->setByteSize(\strlen($pngBytes));
        $this->entityManager->persist($receipt);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => ['space' => $spaceIri, 'name' => 'Acme Co', 'currency' => 'USD'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        [$bpIri] = $this->createProject($client, $spaceIri, $this->iri($clientRow));

        // A billable expense with the receipt, invoiced on its own.
        $client->request('POST', '/expenses', [
            'json' => [
                'space' => $spaceIri,
                'project' => $bpIri,
                'spentOn' => '2026-07-10',
                'category' => 'Travel',
                'description' => 'Taxi to the site',
                'amount' => 2500,
                'receipt' => '/media-objects/' . $receipt->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $invoice = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['project' => $bpIri],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $invoiceId = $invoice['id'];
        $this->assertIsString($invoiceId);

        // The renderer collects the image receipt as an embeddable data URI…
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $entity = $em->getRepository(Invoice::class)->find($invoiceId);
        $this->assertInstanceOf(Invoice::class, $entity);
        $renderer = static::getContainer()->get(\App\Service\InvoicePdfRenderer::class);
        $receipts = $renderer->receiptImages($entity);
        $this->assertCount(1, $receipts);
        $this->assertStringStartsWith('data:image/png;base64,', $receipts[0]['dataUri']);
        $this->assertSame('Expense — Travel: Taxi to the site', $receipts[0]['caption']);

        // …and the full PDF still renders.
        $pdf = $client->request('GET', '/invoices/' . $invoiceId . '/pdf');
        $this->assertResponseStatusCodeSame(200);
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function testDiscountAppliesBetweenSubtotalAndTax(): void
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

        // 25% off 20000 = 5000; tax 10% on the discounted 15000 = 1500.
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'taxRate' => 1000,
                'discountType' => 'percent',
                'discountValue' => 2500,
                'lineItems' => [['description' => 'Work', 'quantity' => 4, 'unitAmount' => 5000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(20000, $invoice['subtotal'] ?? null);
        $this->assertSame(5000, $invoice['discountAmount'] ?? null);
        $this->assertSame(1500, $invoice['taxAmount'] ?? null);
        $this->assertSame(16500, $invoice['total'] ?? null);

        // Switch to a fixed 30.00 discount: tax 10% on 17000 = 1700.
        $patched = $client->request('PATCH', $this->iri($invoice), [
            'json' => ['discountType' => 'fixed', 'discountValue' => 3000],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(3000, $patched['discountAmount'] ?? null);
        $this->assertSame(1700, $patched['taxAmount'] ?? null);
        $this->assertSame(18700, $patched['total'] ?? null);

        // A percent discount over 100% is rejected.
        $client->request('PATCH', $this->iri($invoice), [
            'json' => ['discountType' => 'percent', 'discountValue' => 10001],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testPartialPaymentsTrackBalanceAndFlipStatus(): void
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
        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'dueDate' => $yesterday,
                'lineItems' => [['description' => 'Work', 'quantity' => 1, 'unitAmount' => 10000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $invoiceIri = $invoice['@id'];
        $this->assertIsString($invoiceIri);

        // Payments can't be recorded on a draft.
        $client->request('POST', $invoiceIri . '/payments', [
            'json' => ['amount' => 1000],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);

        $client->request('POST', $invoiceIri . '/send', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Partial payment: status stays sent, balance shrinks.
        $partial = $client->request('POST', $invoiceIri . '/payments', [
            'json' => ['amount' => 4000, 'method' => 'bank transfer', 'note' => 'first half'],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('sent', $partial['status'] ?? null);
        $this->assertSame(4000, $partial['amountPaid'] ?? null);
        $this->assertSame(6000, $partial['balanceDue'] ?? null);

        // Overpaying is rejected.
        $client->request('POST', $invoiceIri . '/payments', [
            'json' => ['amount' => 7000],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        // Paying the rest flips the invoice to paid.
        $final = $client->request('POST', $invoiceIri . '/payments', [
            'json' => ['amount' => 6000],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertSame('paid', $final['status'] ?? null);
        $this->assertSame(0, $final['balanceDue'] ?? null);
        $payments = $final['payments'] ?? null;
        $this->assertIsArray($payments);
        $this->assertCount(2, $payments);
        // Same-day payments share a paidOn — pick the 6000 row by amount.
        $lastId = null;
        foreach ($payments as $row) {
            $this->assertIsArray($row);
            if (6000 === ($row['amount'] ?? null)) {
                $lastId = $row['id'];
            }
        }
        $this->assertIsString($lastId);

        // Removing the final payment reopens the balance — past due, so overdue.
        $reopened = $client->request('DELETE', $invoiceIri . '/payments/' . $lastId)->toArray();
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('overdue', $reopened['status'] ?? null);
        $this->assertSame(6000, $reopened['balanceDue'] ?? null);

        // mark-paid records the remaining balance as a manual payment.
        $client->request('POST', $invoiceIri . '/mark-paid', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $refreshed = $client->request('GET', $invoiceIri)->toArray();
        $this->assertSame('paid', $refreshed['status'] ?? null);
        $this->assertSame(10000, $refreshed['amountPaid'] ?? null);
        $paymentRows = $refreshed['payments'] ?? null;
        $this->assertIsArray($paymentRows);
        $this->assertCount(2, $paymentRows);
    }

    private function trackTime(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        string $spaceIri,
        string $projectIri,
        string $categoryIri,
        string $startedAt,
        string $endedAt,
    ): void {
        // Billability is derived from the category, not sent per entry.
        $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'project' => $projectIri,
                'category' => $categoryIri,
                'startedAt' => $startedAt,
                'endedAt' => $endedAt,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
    }

    /**
     * Admin creates a project (for $clientIri) with three categories — two
     * billable ("Dev" @ 6000, "Build" @ 8000) with distinct rates and one
     * non-billable ("Internal" @ 0) — so tracked-time tests can hit distinct
     * rates and the non-billable exclusion. Billability is a per-category flag.
     * Returns [projectIri, devCategoryIri, buildCategoryIri, internalCategoryIri].
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function createProject(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        string $spaceIri,
        string $clientIri,
    ): array {
        $row = $client->request('POST', '/projects', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientIri,
                'name' => 'Acme Website',
                'currency' => 'USD',
                'categories' => [
                    ['name' => 'Dev', 'billingRate' => 6000, 'position' => 0],
                    ['name' => 'Build', 'billingRate' => 8000, 'position' => 1],
                    ['name' => 'Internal', 'billingRate' => 0, 'position' => 2, 'billable' => false],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);

        $bpIri = $this->iri($row);
        $categories = $row['categories'] ?? [];
        $this->assertIsArray($categories);
        $byName = [];
        foreach ($categories as $category) {
            $this->assertIsArray($category);
            $name = $category['name'] ?? null;
            $iri = $category['@id'] ?? null;
            if (is_string($name) && is_string($iri)) {
                $byName[$name] = $iri;
            }
        }
        $this->assertArrayHasKey('Dev', $byName);
        $this->assertArrayHasKey('Build', $byName);
        $this->assertArrayHasKey('Internal', $byName);

        return [$bpIri, $byName['Dev'], $byName['Build'], $byName['Internal']];
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
