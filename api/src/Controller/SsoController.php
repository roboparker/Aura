<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SsoIdentity;
use App\Entity\User;
use App\Service\AvatarColorService;
use App\Service\PersonalSpaceProvisioner;
use App\Service\SsoClientFactory;
use App\Service\SsoConfig;
use App\Service\WaitlistSettings;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Social / enterprise sign-in and account linking (#267). Providers (Google,
 * Microsoft, Apple, Facebook, GitHub, Okta, Auth0) are configured at runtime by
 * an admin ({@see SsoConfig}) — a provider's button appears only when it's
 * enabled and fully configured. Flows:
 *
 *  - Sign in / sign up:  GET /auth/sso/{provider}/start → provider → callback.
 *  - Link to current account:  GET /me/sso/{provider}/connect → … → callback.
 *  - Callback:  GET|POST /auth/sso/{provider}/check (POST for Apple form_post).
 *  - Manage:  GET /me/sso/identities, DELETE /me/sso/{provider}.
 *
 * The OAuth dance runs directly against league providers built per-request by
 * {@see SsoClientFactory} (CSRF `state` stored in the session here); this
 * controller owns the identity → user resolution and a programmatic login.
 */
final class SsoController extends AbstractController
{
    private const LINK_FLAG = 'sso_link';
    private const NEXT_KEY = 'sso_next';
    private const STATE_KEY = 'sso_oauth_state';

    public function __construct(
        private readonly SsoConfig $config,
        private readonly SsoClientFactory $factory,
        private readonly EntityManagerInterface $em,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AvatarColorService $colorService,
        private readonly PersonalSpaceProvisioner $spaceProvisioner,
        private readonly WaitlistSettings $waitlistSettings,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    private function redirectUri(string $provider): string
    {
        return $this->generateUrl(
            'sso_check',
            ['provider' => $provider],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    #[Route('/auth/sso/status', name: 'sso_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->json(['providers' => $this->config->enabledProviders()]);
    }

    #[Route('/auth/sso/{provider}/start', name: 'sso_start', methods: ['GET'])]
    public function start(string $provider, Request $request): RedirectResponse|JsonResponse
    {
        $client = $this->factory->create($provider, $this->redirectUri($provider));
        if (null === $client) {
            return $this->json(['error' => 'Unknown or unconfigured provider.'], 404);
        }
        $session = $request->getSession();
        $session->remove(self::LINK_FLAG);
        $session->set(self::NEXT_KEY, $this->safeNext($request->query->getString('next')));

        return $this->authorize($client, $provider, $session);
    }

    #[Route('/me/sso/{provider}/connect', name: 'sso_connect', methods: ['GET'])]
    public function connect(string $provider, Request $request): RedirectResponse|JsonResponse
    {
        $client = $this->factory->create($provider, $this->redirectUri($provider));
        if (null === $client) {
            return $this->json(['error' => 'Unknown or unconfigured provider.'], 404);
        }
        // Access-controlled by ^/me/sso (ROLE_USER); flag the callback as a link.
        $request->getSession()->set(self::LINK_FLAG, true);

        return $this->authorize($client, $provider, $request->getSession());
    }

    private function authorize(
        AbstractProvider $client,
        string $provider,
        \Symfony\Component\HttpFoundation\Session\SessionInterface $session,
    ): RedirectResponse {
        $url = $client->getAuthorizationUrl(['scope' => $this->factory->scopes($provider)]);
        $session->set(self::STATE_KEY, $client->getState());

        return $this->redirect($url);
    }

    // POST is allowed for Apple, which returns the callback via form_post.
    #[Route('/auth/sso/{provider}/check', name: 'sso_check', methods: ['GET', 'POST'])]
    public function check(string $provider, Request $request): RedirectResponse
    {
        $session = $request->getSession();
        $linkMode = true === $session->get(self::LINK_FLAG);
        $session->remove(self::LINK_FLAG);

        $client = $this->factory->create($provider, $this->redirectUri($provider));
        if (null === $client) {
            return $this->redirect($this->failTarget($linkMode, 'unknown_provider'));
        }

        // CSRF: the returned state must match what we stored on start.
        $state = $request->query->has('state')
            ? $request->query->get('state')
            : $request->request->get('state');
        $expected = $session->remove(self::STATE_KEY);
        if (!is_string($state) || '' === $state || $state !== $expected) {
            return $this->redirect($this->failTarget($linkMode, 'bad_state'));
        }

        $code = $request->query->has('code')
            ? $request->query->get('code')
            : $request->request->get('code');
        if (!is_string($code) || '' === $code) {
            return $this->redirect($this->failTarget($linkMode, 'no_code'));
        }

        try {
            $token = $client->getAccessToken('authorization_code', ['code' => $code]);
            if (!$token instanceof AccessToken) {
                throw new \RuntimeException('Unexpected access token type.');
            }
            $identity = $this->resolveIdentity($provider, $client, $token);
        } catch (\Throwable) {
            return $this->redirect($this->failTarget($linkMode, 'exchange_failed'));
        }

        // Apple only sends the name once, in a `user` JSON field on first login.
        if ('apple' === $provider && '' === $identity['given']) {
            $identity = $this->applyAppleName($identity, $request);
        }

        if ('' === $identity['subject']) {
            return $this->redirect($this->failTarget($linkMode, 'no_identity'));
        }

        if ($linkMode) {
            return $this->handleLink($provider, $identity);
        }

        $next = $session->remove(self::NEXT_KEY);

        return $this->handleLogin($provider, $identity, is_string($next) ? $next : '/');
    }

    /**
     * @param array{subject: string, email: string, emailVerified: bool, given: string, family: string} $identity
     */
    private function handleLogin(string $provider, array $identity, string $next): RedirectResponse
    {
        $existing = $this->em->getRepository(SsoIdentity::class)
            ->findOneBy(['provider' => $provider, 'subject' => $identity['subject']]);
        if (null !== $existing && null !== $existing->getUser()) {
            return $this->loginAndRedirect($existing->getUser(), $next);
        }

        // No linked identity yet — we need a verified email to link or provision.
        if ('' === $identity['email'] || !$identity['emailVerified']) {
            return $this->redirect($this->signinError('email_unverified'));
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $identity['email']]);
        if (null === $user) {
            $user = $this->provisionUser($identity);
        }

        $this->linkIdentity($user, $provider, $identity);
        $this->em->flush();

        return $this->loginAndRedirect($user, $next);
    }

    /**
     * @param array{subject: string, email: string, emailVerified: bool, given: string, family: string} $identity
     */
    private function handleLink(string $provider, array $identity): RedirectResponse
    {
        $current = $this->tokenStorage->getToken()?->getUser();
        if (!$current instanceof User) {
            return $this->redirect($this->signinError('link_requires_login'));
        }

        $existing = $this->em->getRepository(SsoIdentity::class)
            ->findOneBy(['provider' => $provider, 'subject' => $identity['subject']]);
        if (null !== $existing) {
            $sameUser = true === $existing->getUser()?->getId()?->equals($current->getId());

            return $this->redirect($this->settingsResult($sameUser ? 'linked' : 'already_linked', $provider));
        }

        $this->linkIdentity($current, $provider, $identity);
        $this->em->flush();

        return $this->redirect($this->settingsResult('linked', $provider));
    }

    #[Route('/me/sso/identities', name: 'sso_identities', methods: ['GET'])]
    public function identities(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $items = [];
        foreach ($user->getSsoIdentities() as $identity) {
            $items[] = [
                'provider' => $identity->getProvider(),
                'email' => $identity->getEmail(),
                'createdAt' => $identity->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->json([
            'identities' => $items,
            'available' => $this->config->enabledProviders(),
            'hasPassword' => '' !== $user->getPassword(),
        ]);
    }

    #[Route('/me/sso/{provider}', name: 'sso_unlink', methods: ['DELETE'])]
    public function unlink(string $provider, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $identity = null;
        foreach ($user->getSsoIdentities() as $candidate) {
            if ($candidate->getProvider() === $provider) {
                $identity = $candidate;
                break;
            }
        }
        if (null === $identity) {
            return $this->json(['error' => 'Not linked.'], 404);
        }
        if ('' === $user->getPassword() && $user->getSsoIdentities()->count() <= 1) {
            return $this->json([
                'error' => 'Set a password before removing your only sign-in method.',
            ], 422);
        }

        $user->removeSsoIdentity($identity);
        $this->em->remove($identity);
        $this->em->flush();

        return $this->json(['unlinked' => $provider]);
    }

    /**
     * @param array{subject: string, email: string, emailVerified: bool, given: string, family: string} $identity
     */
    private function linkIdentity(User $user, string $provider, array $identity): void
    {
        $row = (new SsoIdentity($provider, $identity['subject']))
            ->setEmail('' !== $identity['email'] ? $identity['email'] : null);
        $user->addSsoIdentity($row);
        $this->em->persist($row);
    }

    /**
     * @param array{subject: string, email: string, emailVerified: bool, given: string, family: string} $identity
     */
    private function provisionUser(array $identity): User
    {
        $user = new User();
        $user->setEmail($identity['email']);
        $user->setGivenName('' !== $identity['given'] ? $identity['given'] : $this->localPart($identity['email']));
        $user->setFamilyName($identity['family']);
        $user->setPersonalizedColor($this->colorService->pick());
        if ($this->waitlistSettings->isEnabled()) {
            $user->setWaitlisted(true);
        }

        $this->em->persist($user);
        $this->spaceProvisioner->provision($user);

        return $user;
    }

    private function loginAndRedirect(User $user, string $next): RedirectResponse
    {
        // Programmatic session login on the `main` firewall (context = firewall
        // name). SSO is treated as a complete sign-in — local 2FA isn't
        // re-challenged here.
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);

        return $this->redirect($this->safeNext($next));
    }

    /**
     * Normalise a provider's user info to a common shape.
     *
     * @return array{subject: string, email: string, emailVerified: bool, given: string, family: string}
     */
    private function resolveIdentity(string $provider, AbstractProvider $client, AccessToken $token): array
    {
        $data = $client->getResourceOwner($token)->toArray();

        return match ($provider) {
            'github' => $this->githubIdentity($data, $token),
            'facebook' => [
                'subject' => $this->pick($data, 'id'),
                'email' => $this->pick($data, 'email'),
                'emailVerified' => '' !== $this->pick($data, 'email'),
                'given' => $this->pick($data, 'first_name'),
                'family' => $this->pick($data, 'last_name'),
            ],
            'apple' => [
                'subject' => $this->pick($data, 'sub', 'id'),
                'email' => $this->pick($data, 'email'),
                'emailVerified' => in_array($data['email_verified'] ?? null, [true, 'true'], true),
                'given' => '',
                'family' => '',
            ],
            // Google + Microsoft + Okta + Auth0 are all OIDC-shaped.
            default => [
                'subject' => $this->pick($data, 'sub', 'oid', 'id'),
                'email' => $this->pick($data, 'email', 'mail', 'upn', 'userPrincipalName', 'preferred_username'),
                'emailVerified' => 'microsoft' === $provider
                    ? true
                    : in_array($data['email_verified'] ?? null, [true, 'true'], true) || '' !== $this->pick($data, 'email'),
                'given' => $this->pick($data, 'given_name', 'givenName'),
                'family' => $this->pick($data, 'family_name', 'surname'),
            ],
        };
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function pick(array $data, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $value = trim((string) $data[$key]);
                if ('' !== $value) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * GitHub isn't OIDC: the profile email may be null/unverified, so pull the
     * verified primary from the emails API.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array{subject: string, email: string, emailVerified: bool, given: string, family: string}
     */
    private function githubIdentity(array $data, AccessToken $token): array
    {
        $subject = $this->pick($data, 'id');
        [$given, $family] = $this->splitName($this->pick($data, 'name'));

        $email = '';
        $verified = false;
        try {
            $rows = $this->httpClient->request('GET', 'https://api.github.com/user/emails', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token->getToken(),
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'Madori',
                ],
            ])->toArray(false);
            foreach ($rows as $row) {
                if (!is_array($row) || true !== ($row['verified'] ?? false)) {
                    continue;
                }
                $candidate = isset($row['email']) && is_string($row['email']) ? $row['email'] : '';
                if ('' === $candidate) {
                    continue;
                }
                $email = $candidate;
                $verified = true;
                if (true === ($row['primary'] ?? false)) {
                    break;
                }
            }
        } catch (\Throwable) {
            $email = $this->pick($data, 'email');
        }

        return [
            'subject' => $subject,
            'email' => $email,
            'emailVerified' => $verified,
            'given' => $given,
            'family' => $family,
        ];
    }

    /**
     * @param array{subject: string, email: string, emailVerified: bool, given: string, family: string} $identity
     *
     * @return array{subject: string, email: string, emailVerified: bool, given: string, family: string}
     */
    private function applyAppleName(array $identity, Request $request): array
    {
        $raw = $request->request->get('user');
        if (!is_string($raw) || '' === $raw) {
            return $identity;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['name']) || !is_array($decoded['name'])) {
            return $identity;
        }
        $name = $decoded['name'];
        $identity['given'] = isset($name['firstName']) && is_string($name['firstName']) ? $name['firstName'] : '';
        $identity['family'] = isset($name['lastName']) && is_string($name['lastName']) ? $name['lastName'] : '';

        return $identity;
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $name = trim($name);
        if ('' === $name) {
            return ['', ''];
        }
        $pos = strpos($name, ' ');
        if (false === $pos) {
            return [$name, ''];
        }

        return [substr($name, 0, $pos), trim(substr($name, $pos + 1))];
    }

    private function localPart(string $email): string
    {
        $at = strpos($email, '@');

        return false === $at ? $email : substr($email, 0, $at);
    }

    private function safeNext(string $next): string
    {
        if ('' === $next || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return '/';
        }

        return $next;
    }

    private function failTarget(bool $linkMode, string $reason): string
    {
        return $linkMode ? $this->settingsResult('error', $reason) : $this->signinError($reason);
    }

    private function signinError(string $reason): string
    {
        return '/signin?sso_error=' . urlencode($reason);
    }

    private function settingsResult(string $key, string $value): string
    {
        return '/settings/security?sso_' . $key . '=' . urlencode($value);
    }
}
