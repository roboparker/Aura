<?php

namespace App\Controller;

use App\Entity\GroupInvite;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserInvite;
use App\Repository\GroupInviteRepository;
use App\Repository\UserInviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Add-by-email endpoint for group members. Branches on whether the email
 * matches an existing user:
 *   - existing user → add directly to the group's member set (status: "added")
 *   - unknown email → upsert a UserInvite (one per email), attach a
 *     GroupInvite for this group, rotate the token, and email a signup link
 *     covering every group the address has been invited to. Status: "invited".
 *
 * When the invitee signs up via that link UserPasswordHasherProcessor adds
 * them to all attached groups.
 */
class UserGroupMemberController extends AbstractController
{
    private const INVITE_TTL_DAYS = 14;

    public function __construct(
        private EntityManagerInterface $em,
        private UserInviteRepository $userInviteRepository,
        private GroupInviteRepository $groupInviteRepository,
        private MailerInterface $mailer,
        private ValidatorInterface $validator,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
    }

    #[Route('/groups/{id}/members', name: 'user_group_add_member', methods: ['POST'])]
    public function add(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $group = $this->em->getRepository(UserGroup::class)->find($id);
        if (null === $group) {
            return $this->json(['error' => 'Group not found.'], 404);
        }

        $isOwner = $group->getOwner()?->getId()?->equals($user->getId()) ?? false;
        if (!$isOwner && !$this->isGranted('ROLE_ADMIN')) {
            // Non-owner members shouldn't be able to confirm the group's
            // existence beyond what they see in their listings; 404 matches
            // the access-extension treatment for non-members.
            if (!$group->getMembers()->contains($user)) {
                return $this->json(['error' => 'Group not found.'], 404);
            }
            return $this->json(['error' => 'Only the group owner can add members.'], 403);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        if ('' === $email) {
            return $this->json(['error' => 'Email is required.'], 400);
        }

        $emailViolations = $this->validator->validate($email, [
            new Assert\Email(),
            new Assert\Length(max: 180),
        ]);
        if (count($emailViolations) > 0) {
            return $this->json(['error' => 'Please provide a valid email address.'], 422);
        }

        $candidate = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $candidate) {
            if ($group->getMembers()->contains($candidate)) {
                return $this->json(['error' => 'That user is already a member.'], 409);
            }

            $group->addMember($candidate);
            $this->em->flush();

            return $this->json([
                'status' => 'added',
                'id' => (string) $candidate->getId(),
                '@id' => '/users/' . $candidate->getId(),
                'email' => $candidate->getEmail(),
            ], 200);
        }

        return $this->upsertInvite($group, $email, $user);
    }

    /**
     * Find or create the per-email UserInvite, attach a GroupInvite for
     * this group if one isn't already there, rotate the token, refresh
     * expiry, and resend the email. Token rotation invalidates any
     * older link, so the most recent email is always the authoritative
     * one for that invitee.
     */
    private function upsertInvite(UserGroup $group, string $email, User $invitedBy): JsonResponse
    {
        $invite = $this->userInviteRepository->findByEmail($email);

        if (null !== $invite && null !== $invite->getAcceptedAt()) {
            // The email signed up at some point but we still don't have a
            // matching User row (deleted? race?). Treat as a fresh invite.
            $invite = null;
        }

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = new \DateTimeImmutable(sprintf('+%d days', self::INVITE_TTL_DAYS));

        if (null === $invite) {
            $invite = new UserInvite($email, $tokenHash, $expiresAt);
            $this->em->persist($invite);
        } else {
            $invite->setTokenHash($tokenHash);
            $invite->setExpiresAt($expiresAt);
        }

        $existingGroupInvite = $invite->getId()
            ? $this->groupInviteRepository->findByInviteAndGroup($invite, $group)
            : null;
        if (null === $existingGroupInvite) {
            // Constructor wires the GroupInvite into the parent's collection
            // so cascade=persist picks it up on flush.
            new GroupInvite($invite, $group, $invitedBy);
        }

        $this->em->flush();

        $this->sendInviteEmail($invite, $plainToken);

        return $this->json([
            'status' => 'invited',
            'email' => $invite->getEmail(),
            'inviteId' => (string) $invite->getId(),
            'expiresAt' => $invite->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ], 200);
    }

    private function sendInviteEmail(UserInvite $invite, string $plainToken): void
    {
        $signupUrl = sprintf(
            '%s/signup?invite=%s',
            rtrim($this->frontendUrl, '/'),
            $plainToken,
        );
        $from = $this->mailerFrom ?: 'no-reply@aura.test';

        // The email lists every group the invitee has been asked to join
        // under this single invite — one signup, all groups joined.
        $groupTitles = [];
        foreach ($invite->getGroupInvites() as $gi) {
            $groupTitles[] = $gi->getGroup()->getTitle();
        }
        $groupsLine = match (count($groupTitles)) {
            0 => 'an Aura group',
            1 => sprintf('the "%s" group', $groupTitles[0]),
            default => sprintf('these groups: %s', implode(', ', array_map(
                fn (string $t) => sprintf('"%s"', $t),
                $groupTitles,
            ))),
        };

        $email = (new Email())
            ->from($from)
            ->to($invite->getEmail())
            ->subject('You\'ve been invited to join Aura')
            ->text(sprintf(
                "Hi,\n\nYou've been invited to join %s on Aura. Create your account to accept:\n\n%s\n\nThis invitation expires in %d days. If you weren't expecting this, you can safely ignore the email.\n\n— Aura",
                $groupsLine,
                $signupUrl,
                self::INVITE_TTL_DAYS,
            ))
            ->html(sprintf(
                '<p>Hi,</p><p>You\'ve been invited to join %1$s on Aura. Create your account to accept:</p><p><a href="%2$s">%2$s</a></p><p>This invitation expires in %3$d days. If you weren\'t expecting this, you can safely ignore the email.</p><p>— Aura</p>',
                htmlspecialchars($groupsLine),
                htmlspecialchars($signupUrl),
                self::INVITE_TTL_DAYS,
            ));

        $this->mailer->send($email);
    }
}
