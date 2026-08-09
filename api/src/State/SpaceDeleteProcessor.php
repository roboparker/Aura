<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Space;
use App\Entity\User;
use App\Service\SensitiveActionVerifier;
use App\Service\SpaceDeletionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Step-up confirmation gate on space deletion. The Delete operation's
 * security expression already restricts this to admins of a non-personal
 * space; this processor adds the {@see SensitiveActionVerifier} check on
 * top so a stolen cookie can't nuke a space without the user's TOTP code
 * (or their password when 2FA is off). Once verification passes it hands off
 * to {@see SpaceDeletionService}, which schedules the deletion rather than
 * performing it — see there for why the row now outlives the request.
 *
 * @implements ProcessorInterface<Space, mixed>
 */
final class SpaceDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private SensitiveActionVerifier $verifier,
        private RequestStack $requestStack,
        private SpaceDeletionService $deletion,
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

        // Schedule rather than remove. A space cascades to every board, task,
        // page and comment inside it — other people's work — so it gets the
        // same grace period + emailed restore link as organizations and
        // accounts. The endpoint's contract is unchanged (204 on success); what
        // changed is that the row survives until the purge.
        $this->deletion->softDelete($data, $user);

        return null;
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
