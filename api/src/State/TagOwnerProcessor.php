<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Tag;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Sets the owner of a new Tag to the currently authenticated user. Mirrors
 * TaskOwnerProcessor — the client-supplied `owner` field, if any, is ignored.
 *
 * @implements ProcessorInterface<Tag, Tag>
 */
final class TagOwnerProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Tag, Tag> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
    ) {
    }

    /**
     * @param Tag $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Tag
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'You must be authenticated to create a tag.');
        }

        $data->setOwner($user);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
