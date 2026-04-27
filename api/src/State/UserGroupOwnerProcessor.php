<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Entity\UserGroup;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Stamps the creator as owner of a new UserGroup and adds them to the
 * members collection so collection access checks can look at members alone.
 *
 * @implements ProcessorInterface<UserGroup, UserGroup>
 */
final class UserGroupOwnerProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<UserGroup, UserGroup> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    /**
     * @param UserGroup $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserGroup
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated to create a group.');
        }

        $data->setOwner($user);
        $data->addMember($user);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
