<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use App\Security\ApiTokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/**
 * Keeps the {@see UserSession} registry in sync with cookie/session-backed
 * sign-ins and enforces revocation.
 *
 * On every authenticated `main`-firewall request it upserts a row keyed by
 * `sha256(session id)` (the raw id is never stored), recording UA + IP and
 * touching `lastSeenAt` (throttled). If the matching row has been revoked
 * (per-session revoke, or "sign out everywhere else") the request is
 * short-circuited with a 401 and the session is invalidated — so the other
 * browser is logged out on its next request.
 *
 * Token-authenticated `/mcp` traffic is skipped: tokens are not sessions.
 * Runs after the firewall (priority 4) so the security token is available.
 */
final class SessionRegistryListener implements EventSubscriberInterface
{
    /** Don't write lastSeenAt more often than this (seconds). */
    private const TOUCH_THROTTLE_SECONDS = 60;

    public function __construct(
        private Security $security,
        private UserSessionRepository $sessions,
        private EntityManagerInterface $em,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4],
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
        if ($request->attributes->has(ApiTokenAuthenticator::TOKEN_ATTR)) {
            return;
        }

        // While an admin is impersonating someone the underlying session row
        // still belongs to the admin — don't rewrite it to the impersonated
        // user or spawn a phantom session for them.
        if ($this->tokenStorage->getToken() instanceof SwitchUserToken) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $sessionId = $session->getId();
        if ('' === $sessionId) {
            return;
        }

        $hash = hash('sha256', $sessionId);
        $now = new \DateTimeImmutable();
        $existing = $this->sessions->findOneByHash($hash);

        if (null !== $existing) {
            if ($existing->isRevoked()) {
                // Logged out elsewhere — tear this session down now.
                $this->tokenStorage->setToken(null);
                $session->invalidate();
                $event->setResponse(new JsonResponse(['error' => 'Session has been signed out.'], 401));
                return;
            }

            // Throttle the write — most requests don't need a DB round-trip.
            if ($now->getTimestamp() - $existing->getLastSeenAt()->getTimestamp() >= self::TOUCH_THROTTLE_SECONDS) {
                $existing->touch($now);
                $existing->setIpAddress($request->getClientIp());
                $existing->setUserAgent($request->headers->get('User-Agent'));
                $this->em->flush();
            }
            return;
        }

        $row = new UserSession();
        $row->setUser($user);
        $row->setSessionIdHash($hash);
        $row->setIpAddress($request->getClientIp());
        $row->setUserAgent($request->headers->get('User-Agent'));
        $this->em->persist($row);
        $this->em->flush();
    }
}
