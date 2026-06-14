<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\UserGroup;
use App\Repository\UserGroupRepository;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stamps the creator as owner of a new UserGroup, adds them to the
 * membership roster so collection access checks can look at members
 * alone, and generates a stable, unique slug ("g-handle") from the
 * title.
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
        private AuthenticatedUserResolver $auth,
        private UserGroupRepository $userGroupRepository,
    ) {
    }

    /**
     * @param UserGroup $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserGroup
    {
        $user = $this->auth->requireUser('create a group');

        $data->setOwner($user);
        $data->addMember($user);
        if ('' === $data->getSlug()) {
            $data->setSlug($this->generateUniqueSlug($data->getTitle()));
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * Slugify the title and disambiguate against existing handles by
     * appending an incrementing suffix. Leaves room in the base for the
     * suffix so the column limit is never exceeded.
     */
    private function generateUniqueSlug(string $title): string
    {
        $base = $this->slugify($title);
        if ('' === $base) {
            $base = 'group';
        }
        $base = substr($base, 0, UserGroup::MAX_SLUG_LENGTH - 4);
        $base = trim($base, '-');

        $slug = $base;
        $suffix = 1;
        while (null !== $this->userGroupRepository->findOneBy(['slug' => $slug])) {
            ++$suffix;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    private function slugify(string $value): string
    {
        $lower = strtolower(trim($value));
        $hyphenated = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';

        return trim($hyphenated, '-');
    }
}
