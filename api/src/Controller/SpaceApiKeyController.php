<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\Space;
use App\Entity\SpaceRole;
use App\Entity\User;
use App\State\ApiTokenCreateProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Space-scoped API keys (#space-roles). A key is a programmatic Bearer
 * credential confined to one space, with assigned {@see SpaceRole}s deciding
 * what it can do. Space-admin only. The plaintext is shown exactly once at
 * creation (only the sha256 hash is stored).
 */
final class SpaceApiKeyController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/spaces/{id}/api-keys', name: 'space_api_keys_list', methods: ['GET'])]
    public function list(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->adminSpaceOr($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }

        $keys = $this->em->getRepository(ApiToken::class)
            ->findBy(['space' => $space], ['createdAt' => 'DESC']);

        return $this->json(['keys' => array_map($this->serialize(...), $keys)]);
    }

    #[Route('/spaces/{id}/api-keys', name: 'space_api_keys_create', methods: ['POST'])]
    public function create(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->adminSpaceOr($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }
        \assert($user instanceof User);

        $payload = $request->toArray();
        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        if ('' === $name || mb_strlen($name) > ApiToken::MAX_NAME_LENGTH) {
            return $this->json(['error' => 'A key name (1–80 chars) is required.'], 422);
        }

        $roles = $this->resolveRoles($space, $payload['roles'] ?? []);
        if ($roles instanceof JsonResponse) {
            return $roles;
        }

        $expiresAt = null;
        $rawExpiry = $payload['expiresAt'] ?? null;
        if (is_string($rawExpiry) && '' !== $rawExpiry) {
            try {
                $expiresAt = new \DateTimeImmutable($rawExpiry);
            } catch (\Exception) {
                return $this->json(['error' => 'Invalid expiresAt.'], 422);
            }
        }

        $plaintext = ApiToken::PLAINTEXT_PREFIX . ApiTokenCreateProcessor::generateSecret();
        $key = (new ApiToken())
            ->setUser($user)
            ->setSpace($space)
            ->setName($name)
            ->setTokenHash(hash('sha256', $plaintext))
            ->setExpiresAt($expiresAt);
        foreach ($roles as $role) {
            $key->addRole($role);
        }
        $this->em->persist($key);
        $this->em->flush();

        return $this->json([
            ...$this->serialize($key),
            'plainToken' => $plaintext,
        ], 201);
    }

    #[Route('/spaces/{id}/api-keys/{keyId}', name: 'space_api_keys_delete', methods: ['DELETE'])]
    public function delete(string $id, string $keyId, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->adminSpaceOr($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }
        if (!Uuid::isValid($keyId)) {
            return $this->json(['error' => 'Key not found.'], 404);
        }
        $key = $this->em->getRepository(ApiToken::class)->find($keyId);
        if (null === $key || true !== $key->getSpace()?->getId()?->equals($space->getId())) {
            return $this->json(['error' => 'Key not found.'], 404);
        }
        $this->em->remove($key);
        $this->em->flush();

        return $this->json(null, 204);
    }

    /**
     * @return list<SpaceRole>|JsonResponse
     */
    private function resolveRoles(Space $space, mixed $raw): array|JsonResponse
    {
        if (!is_array($raw)) {
            return $this->json(['error' => 'roles must be an array of role IRIs.'], 422);
        }
        $roles = [];
        foreach ($raw as $entry) {
            if (!is_string($entry)) {
                return $this->json(['error' => 'Each role must be an IRI string.'], 422);
            }
            $uuid = substr($entry, (int) strrpos($entry, '/') + 1);
            if (!Uuid::isValid($uuid)) {
                return $this->json(['error' => sprintf('Invalid role IRI: %s', $entry)], 422);
            }
            $role = $this->em->getRepository(SpaceRole::class)->find($uuid);
            if (null === $role || true !== $role->getSpace()?->getId()?->equals($space->getId())) {
                return $this->json(['error' => 'Role does not belong to this space.'], 422);
            }
            $roles[] = $role;
        }

        return $roles;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ApiToken $key): array
    {
        $roles = [];
        foreach ($key->getRoles() as $role) {
            $roles[] = [
                '@id' => '/space_roles/' . $role->getId(),
                'id' => (string) $role->getId(),
                'name' => $role->getName(),
                'color' => $role->getColor(),
            ];
        }

        return [
            'id' => (string) $key->getId(),
            'name' => $key->getName(),
            'roles' => $roles,
            'createdAt' => $key->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expiresAt' => $key->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $key->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function adminSpaceOr(string $id, ?User $user): Space|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$space->isAdmin($user)) {
            if (!$space->hasMember($user)) {
                return $this->json(['error' => 'Space not found.'], 404);
            }
            return $this->json(['error' => 'Only space admins can manage API keys.'], 403);
        }

        return $space;
    }
}
