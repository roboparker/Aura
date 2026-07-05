<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BillingProject;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Minimal billing-project picker for time tracking. Any member of a space may
 * list its active billing projects with their categories + rates — enough to
 * *select* a project + category on a time entry — without the full
 * read/edit access the admin-reserved `invoices` permission gates on the
 * BillingProject resource. Bypasses {@see \App\Doctrine\BillingProjectAccessExtension}
 * by querying the repository directly after a membership check.
 */
class BillingProjectOptionsController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/spaces/{spaceId}/billing-project-options', name: 'space_billing_project_options', methods: ['GET'])]
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

        $projects = $this->em->getRepository(BillingProject::class)
            ->findBy(['space' => $space, 'archived' => false], ['name' => 'ASC']);

        $options = array_map(
            static function (BillingProject $project): array {
                $categories = [];
                foreach ($project->getCategories() as $category) {
                    $categories[] = [
                        '@id' => '/billing_categories/' . $category->getId(),
                        'id' => (string) $category->getId(),
                        'name' => $category->getName(),
                        'rateAmount' => $category->getRateAmount(),
                    ];
                }

                return [
                    '@id' => '/billing_projects/' . $project->getId(),
                    'id' => (string) $project->getId(),
                    'name' => $project->getName(),
                    'currency' => $project->getCurrency(),
                    'categories' => $categories,
                ];
            },
            $projects,
        );

        return $this->json(['options' => $options]);
    }
}
