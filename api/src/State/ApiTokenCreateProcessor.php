<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ApiToken;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Generates the random secret on POST /api-tokens, persists only the
 * sha256 hash, and stashes the plaintext in the transient `plainToken`
 * property so the POST response (and only the POST response) carries it
 * back to the human. Subsequent GETs intentionally omit it.
 *
 * Mirrors the hash-on-write pattern used by {@see PasswordResetToken}
 * and {@see UserInvite}: the cleartext is generated server-side from
 * `random_bytes` (32 bytes → 256 bits of entropy, base64url-encoded),
 * hashed once, and discarded.
 *
 * @implements ProcessorInterface<ApiToken, ApiToken>
 */
final class ApiTokenCreateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<ApiToken, ApiToken> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    /**
     * @param ApiToken $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ApiToken
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $plaintext = ApiToken::PLAINTEXT_PREFIX . self::generateSecret();

        $data->setUser($user);
        $data->setTokenHash(hash('sha256', $plaintext));

        /** @var ApiToken $persisted */
        $persisted = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        $persisted->setPlainToken($plaintext);

        return $persisted;
    }

    /**
     * 32 random bytes encoded as URL-safe base64 without padding. The
     * resulting string is 43 chars long, alphanumeric plus `-_`, which
     * survives any header transport without escaping.
     */
    private static function generateSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
