<?php

namespace App\Controller;

use App\Entity\Discussion;
use App\Entity\Feedback;
use App\Entity\Page;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Hands the PWA a `mercureAuthorization` cookie scoped to the
 * comments topic for a single parent (task or page). The cookie is
 * signed by the same secret the publisher uses, so the Mercure hub
 * treats the bearer as authorised to subscribe to that topic — and
 * only that topic.
 *
 * Auth mirrors the parent's GET security expression (admin / task
 * owner / task project member / page space member). 404 (not 403)
 * is returned for unreachable IDs so the endpoint can't be used to
 * enumerate parent IDs.
 */
class CommentsMercureTokenController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Authorization $authorization,
    ) {
    }

    #[Route(
        '/tasks/{id}/comments/mercure-token',
        name: 'task_comments_mercure_token',
        methods: ['GET'],
    )]
    public function task(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        return $this->mint($id, $request, $user, Task::class, '/tasks/');
    }

    #[Route(
        '/pages/{id}/comments/mercure-token',
        name: 'page_comments_mercure_token',
        methods: ['GET'],
    )]
    public function page(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        return $this->mint($id, $request, $user, Page::class, '/pages/');
    }

    #[Route(
        '/discussions/{id}/comments/mercure-token',
        name: 'discussion_comments_mercure_token',
        methods: ['GET'],
    )]
    public function discussion(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        return $this->mint($id, $request, $user, Discussion::class, '/discussions/');
    }

    #[Route(
        '/feedback/{id}/comments/mercure-token',
        name: 'feedback_comments_mercure_token',
        methods: ['GET'],
    )]
    public function feedback(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        return $this->mint($id, $request, $user, Feedback::class, '/feedback/');
    }

    /**
     * @param class-string<Task|Page|Discussion|Feedback> $entityClass
     */
    private function mint(
        string $id,
        Request $request,
        ?User $user,
        string $entityClass,
        string $iriPrefix,
    ): Response {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $parent = $this->em->getRepository($entityClass)->find($id);
        if (null === $parent || !$this->canRead($parent, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $topic = $iriPrefix . $parent->getId() . '/comments';
        // Authorization::setCookie stages a Set-Cookie on the request
        // attributes; the Mercure event listener flushes it onto the
        // response just before send. The cookie is path-scoped to the
        // hub URL, so the browser only sends it back when opening an
        // EventSource against /.well-known/mercure.
        $this->authorization->setCookie($request, [$topic]);

        return new JsonResponse(['topic' => $topic]);
    }

    private function canRead(Task|Page|Discussion|Feedback $parent, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        if ($parent instanceof Task) {
            return $parent->isAccessibleBy($user);
        }
        // Feedback is an instance-level board: every authenticated user
        // can read every ticket (the caller is already authenticated here).
        if ($parent instanceof Feedback) {
            return true;
        }
        $space = $parent->getSpace();
        return null !== $space && $space->hasMember($user);
    }
}
