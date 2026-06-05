<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Bulk inbox actions for the current user's notifications. Everything is
 * recipient-scoped server-side: a caller can only flip their own rows,
 * so passing someone else's id is a silent no-op rather than a leak.
 *
 * Routes carry an explicit `priority` so `/notifications/mark-read` and
 * `/notifications/archive` win over API Platform's `/notifications/{id}`
 * item route (which would otherwise swallow them as an id lookup).
 */
class NotificationBulkController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationRepository $notifications,
    ) {
    }

    #[Route('/notifications/mark-read', name: 'notifications_mark_read', methods: ['POST'], priority: 10)]
    public function markRead(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }

        $payload = $request->toArray();
        $now = new \DateTimeImmutable();
        $count = 0;

        if (true === ($payload['all'] ?? false)) {
            foreach ($this->notifications->findUnreadForRecipient($user) as $row) {
                $row->setReadAt($now);
                ++$count;
            }
        } else {
            foreach ($this->resolveOwned($payload, $user) as $row) {
                if (null === $row->getReadAt()) {
                    $row->setReadAt($now);
                    ++$count;
                }
            }
        }

        $this->em->flush();

        return new JsonResponse(['updated' => $count]);
    }

    #[Route('/notifications/archive', name: 'notifications_archive', methods: ['POST'], priority: 10)]
    public function archive(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }

        $payload = $request->toArray();
        $now = new \DateTimeImmutable();
        $count = 0;

        foreach ($this->resolveOwned($payload, $user) as $row) {
            if (null === $row->getArchivedAt()) {
                $row->setArchivedAt($now);
                ++$count;
            }
        }

        $this->em->flush();

        return new JsonResponse(['updated' => $count]);
    }

    /**
     * Loads the caller's notifications named in `payload['ids']`, dropping
     * anything that isn't a valid UUID or doesn't belong to them.
     *
     * @param array<int|string, mixed> $payload
     *
     * @return Notification[]
     */
    private function resolveOwned(array $payload, User $user): array
    {
        $ids = $payload['ids'] ?? [];
        if (!is_array($ids) || [] === $ids) {
            return [];
        }

        $valid = [];
        foreach ($ids as $id) {
            if (is_string($id) && Uuid::isValid($id)) {
                $valid[] = $id;
            }
        }
        if ([] === $valid) {
            return [];
        }

        return $this->notifications->findBy(['id' => $valid, 'recipient' => $user]);
    }
}
