<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\OAuthConnection;
use App\Entity\User;
use App\Service\CalendarSubscriptionManager;
use App\Service\OAuthTokenService;
use App\Service\SsoClientFactory;
use App\Service\SsoConfig;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * OAuth account connections for API access (#581) — link a Google or Microsoft
 * account so features like calendar sync (#582) can call the provider on the
 * user's behalf. Distinct from SSO sign-in: it requests calendar + offline
 * scopes and stores a refresh token. Reuses the admin-configured provider app
 * credentials ({@see SsoConfig}) via {@see SsoClientFactory::createConfigured}.
 *
 * All routes are ROLE_USER (^/me/connections in security.yaml).
 */
final class OAuthConnectionController extends AbstractController
{
    private const PROVIDERS = ['google', 'microsoft'];

    /** Scopes requested when connecting (offline access → refresh token). */
    private const SCOPES = [
        'google' => ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/calendar'],
        'microsoft' => ['openid', 'email', 'profile', 'offline_access', 'https://graph.microsoft.com/Calendars.ReadWrite'],
    ];

    private const STATE_KEY = 'oauth_conn_state';

    public function __construct(
        private readonly SsoConfig $config,
        private readonly SsoClientFactory $factory,
        private readonly OAuthTokenService $tokens,
        private readonly EntityManagerInterface $em,
        private readonly CalendarSubscriptionManager $subscriptions,
    ) {
    }

    private function redirectUri(string $provider): string
    {
        return $this->generateUrl(
            'oauth_connection_check',
            ['provider' => $provider],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /** @return list<string> providers configured (connectable) on this instance */
    private function connectable(): array
    {
        return array_values(array_filter(
            self::PROVIDERS,
            fn (string $p): bool => $this->config->isConfigured($p),
        ));
    }

    #[Route('/me/connections', name: 'oauth_connections_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $rows = $this->em->getRepository(OAuthConnection::class)->findBy(['user' => $user]);
        $connections = [];
        foreach ($rows as $row) {
            $connections[] = [
                'provider' => $row->getProvider(),
                'email' => $row->getEmail(),
                'scopes' => $row->getScopes(),
                'connectedAt' => $row->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->json(['connections' => $connections, 'available' => $this->connectable()]);
    }

    #[Route('/me/connections/{provider}/connect', name: 'oauth_connection_connect', methods: ['GET'])]
    public function connect(string $provider, Request $request): RedirectResponse|JsonResponse
    {
        $client = $this->buildClient($provider);
        if (null === $client) {
            return $this->json(['error' => 'Unknown or unconfigured provider.'], 404);
        }

        $options = ['scope' => self::SCOPES[$provider]];
        if ('google' === $provider) {
            // Force a refresh token even on re-consent.
            $options['access_type'] = 'offline';
            $options['prompt'] = 'consent';
        }

        $url = $client->getAuthorizationUrl($options);
        $request->getSession()->set(self::STATE_KEY, $client->getState());

        return $this->redirect($url);
    }

    #[Route('/me/connections/{provider}/check', name: 'oauth_connection_check', methods: ['GET'])]
    public function check(string $provider, Request $request, #[CurrentUser] ?User $user): RedirectResponse
    {
        if (null === $user) {
            return $this->redirect('/signin');
        }
        $client = $this->buildClient($provider);
        if (null === $client) {
            return $this->redirect($this->result('error', 'unknown_provider'));
        }

        $session = $request->getSession();
        $state = $request->query->get('state');
        $expected = $session->remove(self::STATE_KEY);
        if (!is_string($state) || '' === $state || $state !== $expected) {
            return $this->redirect($this->result('error', 'bad_state'));
        }
        $code = $request->query->get('code');
        if (!is_string($code) || '' === $code) {
            return $this->redirect($this->result('error', 'no_code'));
        }

        try {
            $token = $client->getAccessToken('authorization_code', ['code' => $code]);
            if (!$token instanceof AccessToken) {
                throw new \RuntimeException('Unexpected access token type.');
            }
            [$externalId, $email] = $this->accountIdentity($client, $token);
        } catch (\Throwable) {
            return $this->redirect($this->result('error', 'exchange_failed'));
        }

        $connection = $this->em->getRepository(OAuthConnection::class)
            ->findOneBy(['user' => $user, 'provider' => $provider])
            ?? (new OAuthConnection($provider))->setUser($user);
        $connection->setExternalId('' !== $externalId ? $externalId : null);
        $connection->setEmail('' !== $email ? $email : null);
        $connection->setScopes(self::SCOPES[$provider]);
        $this->tokens->store($connection, $token);

        $this->em->persist($connection);
        $this->em->flush();

        return $this->redirect($this->result('connected', $provider));
    }

    #[Route('/me/connections/{provider}', name: 'oauth_connection_disconnect', methods: ['DELETE'])]
    public function disconnect(string $provider, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $connection = $this->em->getRepository(OAuthConnection::class)
            ->findOneBy(['user' => $user, 'provider' => $provider]);
        if (null === $connection) {
            return $this->json(['error' => 'Not connected.'], 404);
        }
        // Best-effort teardown of the provider's change-notification channel
        // while we still hold a valid token (#582 Phase D); no-ops when there's
        // no subscription (the dark-launched default).
        $this->subscriptions->remove($connection);
        $this->em->remove($connection);
        $this->em->flush();

        return $this->json(['disconnected' => $provider]);
    }

    private function buildClient(string $provider): ?AbstractProvider
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            return null;
        }

        return $this->factory->createConfigured($provider, $this->redirectUri($provider));
    }

    /**
     * @return array{0: string, 1: string} [externalId, email]
     */
    private function accountIdentity(AbstractProvider $client, AccessToken $token): array
    {
        $data = $client->getResourceOwner($token)->toArray();
        $pick = static function (string ...$keys) use ($data): string {
            foreach ($keys as $key) {
                if (isset($data[$key]) && is_scalar($data[$key]) && '' !== (string) $data[$key]) {
                    return (string) $data[$key];
                }
            }

            return '';
        };

        return [
            $pick('sub', 'oid', 'id'),
            $pick('email', 'mail', 'userPrincipalName', 'preferred_username'),
        ];
    }

    private function result(string $key, string $value): string
    {
        return '/settings/integrations?' . $key . '=' . urlencode($value);
    }
}
