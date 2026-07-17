<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Client retainers (#673): deposits build a pre-paid balance, invoices draw
 * it down (payment method "retainer"), and removing a retainer payment
 * refunds the draw with a reversing deposit.
 */
class RetainerTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\RetainerTransaction')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Invoice')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testDepositsDrawDownAndReversals(): void
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
        $clientId = $clientRow['id'];
        $this->assertIsString($clientId);

        // An issued 50.00 invoice to draw against.
        $invoice = $client->request('POST', '/invoices', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'lineItems' => [['description' => 'Work', 'quantity' => 1, 'unitAmount' => 5000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $invoiceIri = $invoice['@id'];
        $this->assertIsString($invoiceIri);

        // Applying to a draft is refused; deposits are admin-reserved.
        $client->request('POST', $invoiceIri . '/apply-retainer', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);
        $client->loginUser($member);
        $client->request('POST', '/clients/' . $clientId . '/retainer-deposits', [
            'json' => ['amount' => 10000],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        // Admin records a 100.00 deposit.
        $client->loginUser($admin);
        $deposited = $client->request('POST', '/clients/' . $clientId . '/retainer-deposits', [
            'json' => ['amount' => 10000, 'note' => 'Q3 retainer'],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(10000, $deposited['balance'] ?? null);

        // Issue the invoice, then draw from the retainer — capped at the
        // balance due, flipping the invoice paid.
        $client->request('POST', $invoiceIri . '/issue', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $applied = $client->request('POST', $invoiceIri . '/apply-retainer', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(5000, $applied['applied'] ?? null);
        $this->assertSame(0, $applied['balanceDue'] ?? null);
        $this->assertSame(5000, $applied['retainerBalance'] ?? null);
        $this->assertSame('paid', $applied['status'] ?? null);

        // The ledger shows the deposit + the draw (with the invoice number).
        $ledger = $client->request('GET', '/clients/' . $clientId . '/retainer')->toArray();
        $this->assertSame(5000, $ledger['balance'] ?? null);
        $transactions = $ledger['transactions'] ?? null;
        $this->assertIsArray($transactions);
        $this->assertCount(2, $transactions);

        // Nothing due → a second apply is refused.
        $client->request('POST', $invoiceIri . '/apply-retainer', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);

        // Removing the retainer payment refunds the draw and reopens the invoice.
        $full = $client->request('GET', $invoiceIri)->toArray();
        $payments = $full['payments'] ?? null;
        $this->assertIsArray($payments);
        $this->assertCount(1, $payments);
        $paymentRow = $payments[0];
        $this->assertIsArray($paymentRow);
        $this->assertSame('retainer', $paymentRow['method'] ?? null);
        $paymentId = $paymentRow['id'];
        $this->assertIsString($paymentId);

        $client->request('DELETE', $invoiceIri . '/payments/' . $paymentId);
        $this->assertResponseStatusCodeSame(200);
        $refunded = $client->request('GET', '/clients/' . $clientId . '/retainer')->toArray();
        $this->assertSame(10000, $refunded['balance'] ?? null);
        $refundedTxns = $refunded['transactions'] ?? null;
        $this->assertIsArray($refundedTxns);
        $this->assertCount(3, $refundedTxns);
        $reopened = $client->request('GET', $invoiceIri)->toArray();
        $this->assertSame('sent', $reopened['status'] ?? null);
        $this->assertSame(5000, $reopened['balanceDue'] ?? null);
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
