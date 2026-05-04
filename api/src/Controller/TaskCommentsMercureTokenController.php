<?php

namespace App\Controller;

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
 * Hands the PWA a `mercureAuthorization` cookie scoped to the comments
 * topic for a single task. The cookie is signed by the same secret the
 * publisher uses, so the Mercure hub treats the bearer as authorised to
 * subscribe to `/tasks/{id}/comments` — and only that topic.
 *
 * Auth mirrors the Task GET security expression (admin / task owner /
 * project member). 404 (not 403) is returned for unreachable IDs so the
 * endpoint can't be used to enumerate task IDs.
 */
class TaskCommentsMercureTokenController extends AbstractController
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
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $task = $this->em->getRepository(Task::class)->find($id);
        if (null === $task) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }
        if (!$this->canRead($task, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $topic = '/tasks/' . $task->getId() . '/comments';
        // Authorization::setCookie stages a Set-Cookie on the request
        // attributes; the Mercure event listener flushes it onto the
        // response just before send. The cookie is path-scoped to the
        // hub URL, so the browser only sends it back when opening an
        // EventSource against /.well-known/mercure.
        $this->authorization->setCookie($request, [$topic]);

        return new JsonResponse(['topic' => $topic]);
    }

    private function canRead(Task $task, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        if ($task->getOwner()?->getId()?->equals($user->getId())) {
            return true;
        }
        $project = $task->getProject();
        if (null === $project) {
            return false;
        }
        foreach ($project->getMembers() as $member) {
            if ($member->getId()?->equals($user->getId())) {
                return true;
            }
        }
        return false;
    }
}
