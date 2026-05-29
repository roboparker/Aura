<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Service\SpaceMemberAdder;
use Doctrine\ORM\EntityManagerInterface;
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
 * If the request body included `invites`, every email is dispatched
 * through {@see SpaceMemberAdder} after the space row lands so the
 * mailer sees a persisted parent. Duplicates and the creator's own
 * address are dropped without erroring — the form's intent is
 * "invite these N people" and an accidental self-entry shouldn't
 * fail the whole create.
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
        private EntityManagerInterface $em,
        private SpaceMemberAdder $memberAdder,
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

        $rawInvites = $data->getInvites();
        // Empty list before persist so an accidental serialization
        // doesn't echo the transient field back on the response.
        $data->setInvites([]);

        /** @var Space $space */
        $space = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        $invites = $this->normaliseInvites($rawInvites, $user);
        if ([] === $invites) {
            return $space;
        }

        // Run the per-email branch through the shared service so the
        // existing-user vs unknown-email logic stays in one place.
        $results = [];
        foreach ($invites as $email) {
            $results[] = $this->memberAdder->add($space, $email, $user);
        }
        $this->em->flush();

        // Dispatch signup emails AFTER the flush so the mailer sees a
        // persisted parent — same ordering as SpaceMemberController.
        foreach ($results as $result) {
            if ('invited' === $result['status']) {
                $this->memberAdder->sendInviteEmail($result['invite'], $result['plainToken']);
            }
        }

        return $space;
    }

    /**
     * Normalise the wire input: trim, lowercase, dedupe, drop empties
     * and the creator's own address. Returns a clean list ready for
     * the loop.
     *
     * @param list<string> $raw
     * @return list<string>
     */
    private function normaliseInvites(array $raw, User $creator): array
    {
        $creatorEmail = strtolower($creator->getEmail());
        $seen = [];
        $out = [];
        foreach ($raw as $entry) {
            $normalised = strtolower(trim($entry));
            if ('' === $normalised || $normalised === $creatorEmail) {
                continue;
            }
            if (isset($seen[$normalised])) {
                continue;
            }
            $seen[$normalised] = true;
            $out[] = $normalised;
        }
        return $out;
    }
}
