<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Client portal (#674): one tokenized link listing the client's non-draft
 * invoices + estimates, with portal-scoped PDF / pay / accept endpoints.
 * Minting is admin-reserved; rotation invalidates the old link.
 */
class ClientPortalTest extends ApiTestCase
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

    public function testPortalListsDocumentsAndScopesActions(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $spaceIri = '/spaces/' . $space->getId();

        $client = static::createClient();
        $client->loginUser($admin);
        $clientRow = $client->request('POST', '/clients', [
            'json' => [
                'space' => $spaceIri,
                'name' => 'Acme Co',
                'email' => 'billing@acme.test',
                'currency' => 'USD',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $clientIri = $clientRow['@id'];
        $this->assertIsString($clientIri);
        $clientId = $clientRow['id'];
        $this->assertIsString($clientId);

        // One issued invoice, one draft (drafts stay internal), one sent estimate.
        $makeInvoice = function () use ($client, $spaceIri, $clientIri): array {
            $row = $client->request('POST', '/invoices', [
                'json' => [
                    'space' => $spaceIri,
                    'client' => $clientIri,
                    'currency' => 'USD',
                    'lineItems' => [['description' => 'Work', 'quantity' => 1, 'unitAmount' => 5000]],
                ],
                'headers' => ['Content-Type' => 'application/ld+json'],
            ])->toArray();
            $this->assertResponseStatusCodeSame(201);

            return $row;
        };
        $issued = $makeInvoice();
        $client->request('POST', $this->iri($issued) . '/issue', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $makeInvoice(); // stays draft

        $estimate = $client->request('POST', '/estimates', [
            'json' => [
                'space' => $spaceIri,
                'client' => $clientIri,
                'currency' => 'USD',
                'lineItems' => [['description' => 'Design', 'quantity' => 2, 'unitAmount' => 5000, 'position' => 0]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $client->request('POST', $this->iri($estimate) . '/send', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Minting is admin-reserved: a plain member is refused.
        $client->loginUser($member);
        $client->request('POST', '/clients/' . $clientId . '/portal-link', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        // Admin mints with send:true — the client is emailed the link.
        $client->loginUser($admin);
        $minted = $client->request('POST', '/clients/' . $clientId . '/portal-link', [
            'json' => ['send' => true],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($minted['emailed'] ?? null);
        $this->assertEmailCount(1);
        $token = $minted['token'];
        $this->assertIsString($token);

        // Public portal view: the issued invoice + sent estimate, no draft.
        $portal = $client->request('GET', '/portal/' . $token)->toArray();
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('Acme Co', $portal['clientName'] ?? null);
        $invoices = $portal['invoices'] ?? null;
        $this->assertIsArray($invoices);
        $this->assertCount(1, $invoices);
        $invoiceRow = $invoices[0];
        $this->assertIsArray($invoiceRow);
        $this->assertSame('sent', $invoiceRow['status'] ?? null);
        $this->assertSame(5000, $invoiceRow['balanceDue'] ?? null);
        $estimates = $portal['estimates'] ?? null;
        $this->assertIsArray($estimates);
        $this->assertCount(1, $estimates);
        $estimateRow = $estimates[0];
        $this->assertIsArray($estimateRow);
        $this->assertSame('sent', $estimateRow['status'] ?? null);

        // Portal-scoped PDF streams; a wrong document id 404s.
        $invoiceId = $invoiceRow['id'];
        $this->assertIsString($invoiceId);
        $pdf = $client->request('GET', '/portal/' . $token . '/invoices/' . $invoiceId . '/pdf');
        $this->assertResponseStatusCodeSame(200);
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $estimateId = $estimateRow['id'];
        $this->assertIsString($estimateId);
        $client->request('GET', '/portal/' . $token . '/invoices/' . $estimateId . '/pdf');
        $this->assertResponseStatusCodeSame(404);

        // The client accepts the estimate straight from the portal.
        $decided = $client->request('POST', '/portal/' . $token . '/estimates/' . $estimateId . '/accept', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('accepted', $decided['status'] ?? null);
        // Deciding twice 409s.
        $client->request('POST', '/portal/' . $token . '/estimates/' . $estimateId . '/decline', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(409);

        // Rotation: a new mint invalidates the old link.
        $rotated = $client->request('POST', '/clients/' . $clientId . '/portal-link', [
            'json' => [],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $newToken = $rotated['token'];
        $this->assertIsString($newToken);
        $client->request('GET', '/portal/' . $token);
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/portal/' . $newToken);
        $this->assertResponseStatusCodeSame(200);

        // Unknown tokens 404.
        $client->request('GET', '/portal/definitely-not-a-token');
        $this->assertResponseStatusCodeSame(404);
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
