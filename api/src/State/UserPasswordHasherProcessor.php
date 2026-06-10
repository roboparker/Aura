<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Repository\UserInviteRepository;
use App\Service\AvatarColorService;
use App\Service\WaitlistSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

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
        private WaitlistSettings $waitlistSettings,
        #[Autowire(service: 'limiter.signup_ip')]
        private RateLimiterFactoryInterface $signupLimiter,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param User $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        if ($operation instanceof Post) {
            $this->throttleSignup();
        }

        // Hold onto the invite token before eraseCredentials() clears it; we
        // need it post-persist to wire the new user into their invited group.
        $inviteToken = $data->getInviteToken();

        $plainPassword = $data->getPlainPassword();
        if (null !== $plainPassword && '' !== $plainPassword) {
            $data->setPassword(
                $this->passwordHasher->hashPassword($data, $plainPassword)
            );
            $data->eraseCredentials();
        }

        // Sign-up now lets the user pick a color in the avatar-color
        // step of the form. If the POST body included `personalizedColor`,
        // the entity's setter has flipped the explicit flag — leave it
        // alone. Otherwise fall back to the random pick (callers like
        // the email-invite flow and one-shot fixture seeders that don't
        // pass a color still get a valid one without thinking about it).
        if ($operation instanceof Post && !$data->isPersonalizedColorExplicitlySet()) {
            $data->setPersonalizedColor($this->colorService->pick());
        }

        // While the instance is in waitlist mode, a fresh signup lands as a
        // waitlisted account: it can sign in and read /api/me but holds no
        // ROLE_USER until an admin opens signups (WaitlistPromoter). We still
        // provision the personal space + accept invites below — they stay
        // invisible to the user until promotion, and reusing the one signup
        // path avoids a second provisioning flow.
        if ($operation instanceof Post && $this->waitlistSettings->isEnabled()) {
            $data->setWaitlisted(true);
        }

        /** @var User $user */
        $user = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        if ($operation instanceof Post) {
            // Provision the user's personal space (#181). Created here
            // rather than in a Doctrine listener so the membership row
            // and the space land in the same flush as the user — a
            // signup that fails halfway can't leave an orphan space.
            $this->createPersonalSpace($user);
        }

        if ($operation instanceof Post && null !== $inviteToken && '' !== $inviteToken) {
            $this->acceptInvite($user, $inviteToken);
        }

        return $user;
    }

    /**
     * Per-IP throttle on public signups (the `signup_ip` limiter in
     * framework.yaml — env-tunable, relaxed in dev/test). POST /users is
     * PUBLIC_ACCESS, so without this an unauthenticated script can mass-
     * create accounts (each provisioning a personal space and, via the
     * invite flow, sending mail). Keyed by client IP — TRUSTED_PROXIES is
     * configured, so getClientIp() resolves the real caller behind Caddy.
     * No request (CLI seeders, fixtures) means nothing to key on: skip.
     */
    private function throttleSignup(): void
    {
        $clientIp = $this->requestStack->getCurrentRequest()?->getClientIp();
        if (null === $clientIp || '' === $clientIp) {
            return;
        }

        $limit = $this->signupLimiter->create('ip-' . hash('sha256', $clientIp))->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
        throw new TooManyRequestsHttpException(
            $retryAfter,
            'Too many accounts created from this network. Please try again later.',
        );
    }

    /**
     * Create the user's personal "Private" space and add them as its
     * sole admin. The DB enforces "one personal space per user" via a
     * partial unique index on (created_by_id) WHERE is_personal=true,
     * so re-running this for the same user would be caught even if a
     * future code path tried to.
     */
    private function createPersonalSpace(User $user): void
    {
        $space = (new Space())
            ->setName(Space::PERSONAL_SPACE_NAME)
            ->setIsPersonal(true)
            ->setVisibility(Space::VISIBILITY_PRIVATE)
            ->setCreatedBy($user);

        $membership = (new SpaceMembership())
            ->setUser($user)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($membership);

        $this->em->persist($space);
        $this->em->flush();
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
        // Space invites attach the new user as a direct SpaceMembership
        // (#186). Idempotent against the (space, user) unique index in
        // case a parallel signup flow already created the row — the
        // duplicate would surface during flush; we filter it out here.
        foreach ($invite->getSpaceInvites() as $spaceInvite) {
            $space = $spaceInvite->getSpace();
            $alreadyMember = false;
            foreach ($space->getUserMemberships() as $existing) {
                if (true === $existing->getUser()?->getId()?->equals($user->getId())) {
                    $alreadyMember = true;
                    break;
                }
            }
            if (!$alreadyMember) {
                $space->addUserMembership(
                    (new SpaceMembership())
                        ->setUser($user)
                        ->setRole($spaceInvite->getRole()),
                );
            }
        }
        $invite->markAccepted();
        $this->em->flush();
    }
}
