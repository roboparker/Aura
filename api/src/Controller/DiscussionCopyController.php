<?php

namespace App\Controller;

use App\Entity\Discussion;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Deep-clones a discussion into a target space. Target defaults to the
 * source's space when no `space` is supplied. Title gets an idempotent
 * " (copy)" suffix; the clone's author is the current user; pin/lock
 * reset (moderation state stays with the source). Comments are not
 * copied — the cloned thread starts empty.
 */
class DiscussionCopyController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/discussions/{id}/copy', name: 'discussion_copy', methods: ['POST'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Discussion not found.'], 404);
        }

        $source = $this->em->getRepository(Discussion::class)->find($id);
        if (null === $source) {
            return $this->json(['error' => 'Discussion not found.'], 404);
        }
        $sourceSpace = $source->getSpace();
        if (
            !$this->isGranted('ROLE_ADMIN')
            && (null === $sourceSpace || !$sourceSpace->hasMember($user))
        ) {
            return $this->json(['error' => 'Discussion not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $rawSpace = $payload['space'] ?? null;
        $target = $sourceSpace;
        if (is_string($rawSpace) && '' !== trim($rawSpace)) {
            $spaceId = $this->extractIdFromIri($rawSpace);
            if (null === $spaceId) {
                return $this->json(['error' => 'Invalid space IRI.'], 400);
            }
            $target = $this->em->getRepository(Space::class)->find($spaceId);
            if (null === $target) {
                return $this->json(['error' => 'Target space not found.'], 404);
            }
            if (!$this->isGranted('ROLE_ADMIN') && !$target->hasMember($user)) {
                return $this->json(['error' => 'Target space not found.'], 404);
            }
        } elseif (null === $target) {
            return $this->json(['error' => 'Source discussion has no space and no target supplied.'], 400);
        }

        $copy = (new Discussion())
            ->setSpace($target)
            ->setAuthor($user)
            ->setTitle($this->copyTitle($source->getTitle()))
            ->setBody($source->getBody())
            ->setCategory($source->getCategory());

        $this->em->persist($copy);
        $this->em->flush();

        return $this->json([
            '@id' => '/discussions/' . $copy->getId(),
            'id' => (string) $copy->getId(),
            'title' => $copy->getTitle(),
            'space' => '/spaces/' . $target->getId(),
        ], 201);
    }

    private function copyTitle(string $sourceTitle): string
    {
        $suffix = ' (copy)';
        if (str_ends_with($sourceTitle, $suffix)) {
            return $sourceTitle;
        }
        $combined = $sourceTitle . $suffix;
        if (mb_strlen($combined) <= Discussion::MAX_TITLE_LENGTH) {
            return $combined;
        }
        $room = Discussion::MAX_TITLE_LENGTH - mb_strlen($suffix);
        if ($room <= 0) {
            return mb_substr($sourceTitle, 0, Discussion::MAX_TITLE_LENGTH);
        }
        return mb_substr($sourceTitle, 0, $room) . $suffix;
    }

    private function extractIdFromIri(string $iri): ?string
    {
        $trimmed = trim($iri);
        if (Uuid::isValid($trimmed)) {
            return $trimmed;
        }
        if (1 === preg_match('#^/spaces/([0-9a-f-]+)$#i', $trimmed, $m) && Uuid::isValid($m[1])) {
            return $m[1];
        }
        return null;
    }
}
