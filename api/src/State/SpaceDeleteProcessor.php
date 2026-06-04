<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Space;
use App\Entity\User;
use App\Service\SensitiveActionVerifier;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Step-up confirmation gate on space deletion. The Delete operation's
 * security expression already restricts this to admins of a non-personal
 * space; this processor adds the {@see SensitiveActionVerifier} check on
 * top so a stolen cookie can't nuke a space without the user's TOTP code
 * (or their password when 2FA is off). Once verification passes it
 * delegates to the default Doctrine remove processor.
 *
 * @implements ProcessorInterface<Space, mixed>
 */
final class SpaceDeleteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Space, mixed> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
        private Security $security,
        private SensitiveActionVerifier $verifier,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param Space $data
     *
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated.');
        }

        $error = $this->verifier->verify($user, $this->body());
        if (null !== $error) {
            throw new HttpException($error[0], $error[1]);
        }

        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function body(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || '' === $request->getContent()) {
            return [];
        }
        try {
            return $request->toArray();
        } catch (\Throwable) {
            // Malformed JSON body → treat as no credentials supplied,
            // which the verifier rejects with a clear 400.
            return [];
        }
    }
}
