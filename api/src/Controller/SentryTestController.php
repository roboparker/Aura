<?php

namespace App\Controller;

use App\Message\SentryTestFailure;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-only triggers for verifying the Sentry integration end-to-end once the
 * DSNs are configured (see docs/developer/monitoring.md). Each route deliberately
 * produces an error so you can confirm the matching surface reaches Sentry:
 *
 *   - GET  /admin/sentry-test/error   throws in the request → tests API capture.
 *   - POST /admin/sentry-test/worker  queues a job that fails → tests worker
 *                                     capture (the failing job dead-letters into
 *                                     the `failed` transport for inspection).
 *
 * These are non-destructive — they touch no data. Gated to ROLE_ADMIN in
 * security.yaml (^/admin/sentry-test); the denyAccessUnlessGranted calls here
 * are defense-in-depth so the routes stay locked even if that rule is loosened.
 */
class SentryTestController extends AbstractController
{
    #[Route('/admin/sentry-test/error', name: 'admin_sentry_test_error', methods: ['GET'])]
    public function error(): never
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        throw new \RuntimeException('Sentry API test error — triggered from the admin verification page.');
    }

    #[Route('/admin/sentry-test/worker', name: 'admin_sentry_test_worker', methods: ['POST'])]
    public function worker(MessageBusInterface $bus): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $bus->dispatch(new SentryTestFailure());

        return $this->json([
            'queued' => true,
            'detail' => 'Dispatched a job that fails on purpose. Check the Sentry PHP project and the failed queue.',
        ], 202);
    }
}
