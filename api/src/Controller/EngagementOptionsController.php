<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Engagement;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Minimal engagement picker for time tracking. Any member of a space may
 * list its active engagements with their categories + rates — enough to
 * *select* a board + category on a time entry — without the full
 * read/edit access the admin-reserved `invoices` permission gates on the
 * Engagement resource. Bypasses {@see \App\Doctrine\EngagementAccessExtension}
 * by querying the repository directly after a membership check.
 */
class EngagementOptionsController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/spaces/{spaceId}/engagement-options', name: 'space_engagement_options', methods: ['GET'])]
    public function __invoke(string $spaceId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $space = Uuid::isValid($spaceId)
            ? $this->em->getRepository(Space::class)->find(Uuid::fromString($spaceId))
            : null;

        // Existence-hiding: unknown spaces and non-members both get 404.
        if (null === $space || !($this->isGranted('ROLE_ADMIN') || $space->hasMember($user))) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        $boards = $this->em->getRepository(Engagement::class)
            ->findBy(['space' => $space, 'archived' => false], ['name' => 'ASC']);

        $options = array_map(
            static function (Engagement $board): array {
                $categories = [];
                foreach ($board->getCategories() as $category) {
                    $categories[] = [
                        '@id' => '/engagement_categories/' . $category->getId(),
                        'id' => (string) $category->getId(),
                        'name' => $category->getName(),
                        'rateAmount' => $category->getRateAmount(),
                    ];
                }

                return [
                    '@id' => '/engagements/' . $board->getId(),
                    'id' => (string) $board->getId(),
                    'name' => $board->getName(),
                    'currency' => $board->getCurrency(),
                    'categories' => $categories,
                ];
            },
            $boards,
        );

        return $this->json(['options' => $options]);
    }
}
