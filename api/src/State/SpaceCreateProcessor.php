<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Stamps the creator on a new Space and provisions an admin
 * `SpaceMembership` for them in the same flush, so the access
 * extension and entity-level admin checks treat the creator as a
 * full admin from the moment the row is persisted.
 *
 * `isPersonal` is forced to false here — the only path that creates
 * a personal space is {@see UserPasswordHasherProcessor} at signup.
 *
 * @implements ProcessorInterface<Space, Space>
 */
final class SpaceCreateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Space, Space> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    /**
     * @param Space $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Space
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated to create a space.');
        }

        $data->setCreatedBy($user);
        $data->setIsPersonal(false);

        $membership = (new SpaceMembership())
            ->setUser($user)
            ->setRole(Space::ROLE_ADMIN);
        $data->addUserMembership($membership);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
