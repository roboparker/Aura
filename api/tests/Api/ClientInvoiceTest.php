<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Invoice;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
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
        $this->assertCount(2, $invoice['lineItems'] ?? []);
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
