<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provisions a new Organization (#billing Phase 1a): stamps the creator as
 * `createdBy` + adds them as the sole **Owner** (so a new org always has an
 * owner + a seat), and generates a stable, unique `o-`-prefixed slug from the
 * name.
 *
 * @implements ProcessorInterface<Organization, Organization>
 */
final class OrganizationCreateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Organization, Organization> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
        private OrganizationRepository $organizations,
    ) {
    }

    /**
     * @param Organization $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Organization
    {
        $user = $this->auth->requireUser('create an organization');

        $data->setCreatedBy($user);
        $data->addMember($user, Organization::ROLE_OWNER);
        if ('' === $data->getSlug()) {
            $data->setSlug($this->generateUniqueSlug($data->getName()));
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = $this->slugify($name);
        if ('' === $base) {
            $base = 'org';
        }
        // 'o-' prefix + room for a numeric suffix.
        $base = substr($base, 0, 60);
        $base = trim($base, '-');

        $slug = 'o-' . $base;
        $suffix = 1;
        while (null !== $this->organizations->findOneBy(['slug' => $slug])) {
            ++$suffix;
            $slug = 'o-' . $base . '-' . $suffix;
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
