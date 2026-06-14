<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Page;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stamps the creator on a new {@see Page} (#183). Mirrors the
 * task-side {@see TaskOwnerProcessor} and discussion-side
 * {@see DiscussionAuthorProcessor} pattern — the field is read-only
 * on the wire, so the only authority for it is here.
 *
 * @implements ProcessorInterface<Page, Page>
 */
final class PageAuthorProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Page, Page> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
    ) {
    }

    /**
     * @param Page $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Page
    {
        $user = $this->auth->requireUser('create a page');
        $data->setCreatedBy($user);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
