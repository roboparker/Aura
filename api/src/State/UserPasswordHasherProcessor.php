<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Repository\UserInviteRepository;
use App\Service\AvatarColorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @implements ProcessorInterface<User, User>
 */
final class UserPasswordHasherProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<User, User> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private UserPasswordHasherInterface $passwordHasher,
        private AvatarColorService $colorService,
        private UserInviteRepository $inviteRepository,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param User $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        // Hold onto the invite token before eraseCredentials() clears it; we
        // need it post-persist to wire the new user into their invited group.
        $inviteToken = $data->getInviteToken();

        if ($data->getPlainPassword()) {
            $data->setPassword(
                $this->passwordHasher->hashPassword($data, $data->getPlainPassword())
            );
            $data->eraseCredentials();
        }

        if ($operation instanceof Post) {
            $data->setPersonalizedColor($this->colorService->pick());
        }

        /** @var User $user */
        $user = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        if ($operation instanceof Post && null !== $inviteToken && '' !== $inviteToken) {
            $this->acceptInvite($user, $inviteToken);
        }

        return $user;
    }

    /**
     * Apply a UserInvite at signup time: add the new user to every group
     * attached to the invite (one signup, all groups joined) and mark the
     * invite accepted. Token alone isn't enough — the invite email must
     * match the email the user actually signed up with, otherwise
     * possession of a leaked token could redirect group access to the
     * wrong account.
     */
    private function acceptInvite(User $user, string $inviteToken): void
    {
        $invite = $this->inviteRepository->findByTokenHash(hash('sha256', $inviteToken));
        if (null === $invite || !$invite->isPending()) {
            return;
        }

        if (strcasecmp($invite->getEmail(), $user->getEmail()) !== 0) {
            return;
        }

        foreach ($invite->getGroupInvites() as $groupInvite) {
            $groupInvite->getGroup()->addMember($user);
        }
        $invite->markAccepted();
        $this->em->flush();
    }
}
