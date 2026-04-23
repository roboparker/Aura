<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Project;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Stamps the creator as owner of a new Project and adds them to the member
 * set, so access checks can rely solely on `members` without a special case
 * for the owner.
 *
 * @implements ProcessorInterface<Project, Project>
 */
final class ProjectOwnerProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Project, Project> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    /**
     * @param Project $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Project
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated to create a project.');
        }

        $data->setOwner($user);
        $data->addMember($user);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
