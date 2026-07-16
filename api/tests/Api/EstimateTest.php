<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Estimates (#652): derived totals, the send → public accept/decline
 * lifecycle, and conversion into a draft invoice.
 */
class EstimateTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\Estimate')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Invoice')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testEstimateLifecycleAcceptAndConvert(): void
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

        // Totals derive server-side: 2×5000 + 1.5×8000 = 22000; 10% tax = 2200.
        $estimate = $client->request('POST', '/estimates', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'taxRate' => 1000,
                'lineItems' => [
                    ['description' => 'Design', 'quantity' => 2, 'unitAmount' => 5000, 'position' => 0],
                    ['description' => 'Build', 'quantity' => 1.5, 'unitAmount' => 8000, 'position' => 1],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(22000, $estimate['subtotal'] ?? null);
        $this->assertSame(2200, $estimate['taxAmount'] ?? null);
        $this->assertSame(24200, $estimate['total'] ?? null);
        $this->assertSame('draft', $estimate['status'] ?? null);
        $estimateIri = $estimate['@id'];
        $this->assertIsString($estimateIri);

        // Send: assigns EST number, mints the public token, emails the client.
        $sent = $client->request('POST', $estimateIri . '/send', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('EST-0001', $sent['number'] ?? null);
        $this->assertSame('sent', $sent['status'] ?? null);
        $this->assertEmailCount(1);
        $token = $sent['token'];
        $this->assertIsString($token);

        // The public page resolves the estimate; an unknown token 404s.
        $public = $client->request('GET', '/public/estimates/' . $token)->toArray();
        $this->assertSame('EST-0001', $public['number'] ?? null);
        $this->assertSame(24200, $public['total'] ?? null);
        $this->assertSame('Acme Co', $public['billTo'] ?? null);
        $client->request('GET', '/public/estimates/deadbeef');
        $this->assertResponseStatusCodeSame(404);

        // The client accepts from the public page — the creator is emailed.
        $accepted = $client->request('POST', '/public/estimates/' . $token . '/accept', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('accepted', $accepted['status'] ?? null);
        $this->assertEmailCount(1);
        $decision = $this->getMailerMessage();
        $this->assertNotNull($decision);
        $this->assertEmailAddressContains($decision, 'To', 'admin@example.com');

        // A decision is final.
        $client->request('POST', '/public/estimates/' . $token . '/decline', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);

        // Convert: copies the lines onto a fresh draft invoice + back-links it.
        $converted = $client->request('POST', $estimateIri . '/convert', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $invoice = $converted['invoice'] ?? null;
        $this->assertIsArray($invoice);
        $this->assertSame('draft', $invoice['status'] ?? null);
        $this->assertSame(24200, $invoice['total'] ?? null);
        $invoiceIri = $invoice['@id'];
        $this->assertIsString($invoiceIri);

        $invoiceFull = $client->request('GET', $invoiceIri)->toArray();
        $lines = $invoiceFull['lineItems'] ?? null;
        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);

        // At most one conversion.
        $client->request('POST', $estimateIri . '/convert', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);

        $refreshed = $client->request('GET', $estimateIri)->toArray();
        $this->assertSame($invoiceIri, $refreshed['convertedInvoice'] ?? null);
    }

    public function testDeclinedEstimateCannotConvert(): void
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
        $estimate = $client->request('POST', '/estimates', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientRow['@id'],
                'currency' => 'USD',
                'lineItems' => [['description' => 'Work', 'quantity' => 1, 'unitAmount' => 5000]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $estimateIri = $estimate['@id'];
        $this->assertIsString($estimateIri);

        $sent = $client->request('POST', $estimateIri . '/send', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $token = $sent['token'];
        $this->assertIsString($token);

        $client->request('POST', '/public/estimates/' . $token . '/decline', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        $client->request('POST', $estimateIri . '/convert', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);
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
