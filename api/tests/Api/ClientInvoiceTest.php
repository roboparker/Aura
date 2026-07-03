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

        // Two billable completed entries + one non-billable (excluded).
        $this->trackTime($client, $spaceIri, '2026-07-03T09:00:00+00:00', '2026-07-03T10:00:00+00:00', true, 6000);
        $this->trackTime($client, $spaceIri, '2026-07-03T11:00:00+00:00', '2026-07-03T11:30:00+00:00', true, 8000);
        $this->trackTime($client, $spaceIri, '2026-07-03T12:00:00+00:00', '2026-07-03T13:00:00+00:00', false, 9000);

        $response = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['space' => $spaceIri, 'client' => $clientRow['@id']],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $result = $response->toArray();

        // 1h×6000 + 0.5h×8000 = 10000; the non-billable entry is excluded.
        $this->assertSame(2, $result['lineItemCount'] ?? null);
        $this->assertSame(10000, $result['subtotal'] ?? null);

        // The pulled entries are now billed, so a second run has nothing to bill.
        $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['space' => $spaceIri, 'client' => $clientRow['@id']],
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
        $this->trackTime($client, $spaceIri, '2026-07-03T09:00:00+00:00', '2026-07-03T10:00:00+00:00', true, 6000);
        $invoice = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['space' => $spaceIri, 'client' => $clientRow['@id']],
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
        $this->trackTime($client, $spaceIri, '2026-07-03T09:00:00+00:00', '2026-07-03T10:00:00+00:00', true, 6000);

        $invoice = $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['space' => $spaceIri, 'client' => $clientRow['@id']],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();

        // Voiding releases the billed entry, so it can be re-invoiced.
        $client->request('POST', $this->iri($invoice) . '/void', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('POST', '/invoices/from-time-entries', [
            'json' => ['space' => $spaceIri, 'client' => $clientRow['@id']],
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

    private function trackTime(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        string $spaceIri,
        string $startedAt,
        string $endedAt,
        bool $billable,
        int $rateAmount,
    ): void {
        $client->request('POST', '/time_entries', [
            'json' => [
                'space' => $spaceIri,
                'startedAt' => $startedAt,
                'endedAt' => $endedAt,
                'billable' => $billable,
                'rateAmount' => $rateAmount,
                'rateCurrency' => 'USD',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
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
