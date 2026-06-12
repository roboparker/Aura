<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\UsageRecorder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Counts authenticated HTTP API calls per user, one per main request.
 *
 * The authenticated {@see User} is resolved at kernel.request (priority 0, so
 * after the firewall at priority 8) and stashed on the request; the counter is
 * actually incremented at kernel.terminate — *after* the response has been
 * flushed to the client — so the bookkeeping never adds to the request's
 * perceived latency. Resolving the user up-front also sidesteps any token
 * teardown that may have happened by terminate.
 *
 * `/mcp` traffic is excluded here: MCP tool calls are counted at the
 * tool-dispatch chokepoint in {@see \App\Controller\McpController}, which is
 * both more accurate (a single POST can batch several tool calls) and avoids
 * double-counting the JSON-RPC envelope.
 */
final class UsageTrackingListener implements EventSubscriberInterface
{
    /** Request attribute the resolved user is stashed under between request and terminate. */
    private const USER_ATTR = '_aura_usage_user';

    public function __construct(
        private Security $security,
        private UsageRecorder $recorder,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
            KernelEvents::TERMINATE => ['onKernelTerminate', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/mcp')) {
            return;
        }
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $request->attributes->set(self::USER_ATTR, $user);
        }
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $event->getRequest()->attributes->get(self::USER_ATTR);
        if ($user instanceof User) {
            $this->recorder->recordApiCall($user);
        }
    }
}
