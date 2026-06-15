<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Project;
use App\Repository\SpaceRepository;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        private AuthenticatedUserResolver $auth,
        private SpaceRepository $spaceRepository,
    ) {
    }

    /**
     * @param Project $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Project
    {
        $user = $this->auth->requireUser('create a project');

        $data->setOwner($user);

        // Default to the creator's personal space when the client
        // doesn't pick one (#181). Keeps the existing PWA — which has
        // no concept of spaces yet — working unchanged. PR 4 (#187)
        // will let clients send an explicit `space` IRI.
        if (null === $data->getSpace()) {
            $data->setSpace($this->spaceRepository->findPersonalSpaceFor($user));
        }
        // No project-local membership to set up: access flows from the
        // user's membership in `data.space` (#185). The personal space
        // already lists the creator as admin from signup; for shared
        // spaces, the securityPostDenormalize check ensures the caller
        // is a member of the chosen space.

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
