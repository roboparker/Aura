<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

/**
 * Audit trail for admin impersonation (the firewall's switch_user feature).
 *
 * The event fires on both switch-in (?_switch_user=<email>) and switch-out
 * (?_switch_user=_exit). They're told apart by the current token: on switch-in
 * it's the admin's own token and the target is the impersonated user; on
 * switch-out the current token is the SwitchUserToken being unwrapped and the
 * target is the admin being restored.
 */
final class ImpersonationAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [SwitchUserEvent::class => 'onSwitchUser'];
    }

    public function onSwitchUser(SwitchUserEvent $event): void
    {
        $target = $event->getTargetUser();
        $current = $event->getToken()->getUser();

        if ($event->getToken() instanceof SwitchUserToken) {
            // Switch-out: target is the admin, current token holds the
            // impersonated account being unwrapped.
            $this->logger->info('Impersonation ended', [
                'admin' => $this->ref($target),
                'impersonated' => $this->ref($current),
            ]);

            return;
        }

        $this->logger->info('Impersonation started', [
            'admin' => $this->ref($current),
            'impersonated' => $this->ref($target),
        ]);
    }

    private function ref(mixed $user): string
    {
        return $user instanceof User
            ? sprintf('%s <%s>', (string) $user->getId(), (string) $user->getEmail())
            : 'unknown';
    }
}
