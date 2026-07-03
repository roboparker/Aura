<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarSubscription;
use App\Message\PullCalendarChanges;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives provider change-notifications and triggers a targeted pull (#582
 * Phase D). Public (unauthenticated at the firewall, `^/calendar/webhook` in
 * security.yaml) — authenticity is proven by the per-subscription secret the
 * provider echoes back (Google's channel token / Graph's clientState), verified
 * in constant time here. A notification never carries the change itself; it
 * just tells us to run an incremental pull for that connection.
 *
 * Dark-launched with the rest of Phase D: with no webhook base URL configured,
 * no subscriptions are ever created, so this endpoint simply finds nothing to
 * match and no-ops. Notifications for a since-disconnected connection also
 * match no subscription (the row CASCADE-deleted) and are safely ignored.
 */
final class CalendarWebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/calendar/webhook/{provider}', name: 'calendar_webhook', methods: ['POST'])]
    public function receive(string $provider, Request $request): Response
    {
        // Microsoft Graph validates the notificationUrl by POSTing a token to
        // echo back verbatim as text/plain when a subscription is created.
        $validationToken = $request->query->get('validationToken');
        if (is_string($validationToken)) {
            return new Response($validationToken, 200, ['Content-Type' => 'text/plain']);
        }

        return match ($provider) {
            'google' => $this->handleGoogle($request),
            'microsoft' => $this->handleMicrosoft($request),
            default => new Response('', 404),
        };
    }

    private function handleGoogle(Request $request): Response
    {
        $state = $request->headers->get('X-Goog-Resource-State');
        // The first message after watch() is a handshake, not a change.
        if ('sync' === $state) {
            return new Response('', 200);
        }
        $channelId = $request->headers->get('X-Goog-Channel-ID');
        $token = (string) $request->headers->get('X-Goog-Channel-Token');
        if (is_string($channelId) && '' !== $channelId) {
            $subscription = $this->findSubscription('google', $channelId);
            if (null !== $subscription && hash_equals($subscription->getSecret(), $token)) {
                $this->dispatchPull($subscription);
            }
        }

        return new Response('', 200);
    }

    private function handleMicrosoft(Request $request): Response
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return new Response('', 202);
        }
        $value = $payload['value'] ?? null;
        if (is_array($value)) {
            foreach ($value as $note) {
                if (!is_array($note)) {
                    continue;
                }
                $subId = $note['subscriptionId'] ?? null;
                $clientState = $note['clientState'] ?? null;
                if (!is_string($subId) || '' === $subId) {
                    continue;
                }
                $subscription = $this->findSubscription('microsoft', $subId);
                if (null === $subscription) {
                    continue;
                }
                $state = is_string($clientState) ? $clientState : '';
                if (hash_equals($subscription->getSecret(), $state)) {
                    $this->dispatchPull($subscription);
                }
            }
        }

        // Graph expects 202 Accepted within 3 seconds.
        return new Response('', 202);
    }

    private function findSubscription(string $provider, string $channelId): ?CalendarSubscription
    {
        return $this->em->getRepository(CalendarSubscription::class)
            ->findOneBy(['provider' => $provider, 'channelId' => $channelId]);
    }

    private function dispatchPull(CalendarSubscription $subscription): void
    {
        $id = $subscription->getConnection()?->getId();
        if (null !== $id) {
            $this->bus->dispatch(new PullCalendarChanges((string) $id));
        }
    }
}
