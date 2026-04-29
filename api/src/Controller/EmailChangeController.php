<?php

namespace App\Controller;

use App\Entity\EmailChangeRequest;
use App\Entity\User;
use App\Repository\EmailChangeRequestRepository;
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

class EmailChangeController extends AbstractController
{
    private const CONFIRM_TOKEN_TTL_HOURS = 1;
    private const REVERT_TOKEN_TTL_HOURS = 24 * 7; // a week to notice & undo

    public function __construct(
        private EntityManagerInterface $em,
        private EmailChangeRequestRepository $requests,
        private ValidatorInterface $validator,
        private MailerInterface $mailer,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
    }

    /**
     * Authenticated user kicks off an email change. We mail a confirm
     * link to the *new* address; the user's stored email isn't touched
     * until they click it.
     */
    #[Route('/auth/request-email-change', name: 'auth_request_email_change', methods: ['POST'])]
    public function request(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $newEmail = trim((string) ($data['newEmail'] ?? ''));

        if ('' === $newEmail) {
            return $this->json(['error' => 'New email is required.'], 422);
        }

        $violations = $this->validator->validate($newEmail, [new Assert\Email(), new Assert\Length(max: 180)]);
        if (count($violations) > 0) {
            return $this->json(['error' => 'Please enter a valid email address.'], 422);
        }

        if (strcasecmp($newEmail, $user->getEmail()) === 0) {
            return $this->json(['error' => 'That is already your email address.'], 422);
        }

        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $newEmail]);
        if (null !== $existing) {
            // Don't leak whether the address is registered — return success.
            // The flow will silently no-op (no confirm email sent).
            return $this->json(['message' => 'If the address is available, a confirmation email has been sent.']);
        }

        $this->requests->cancelPendingForUser($user);

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = new \DateTimeImmutable(sprintf('+%d hours', self::CONFIRM_TOKEN_TTL_HOURS));

        $changeRequest = new EmailChangeRequest(
            $user,
            $user->getEmail(),
            $newEmail,
            $tokenHash,
            $expiresAt,
        );
        $this->em->persist($changeRequest);
        $this->em->flush();

        $this->sendConfirmEmail($newEmail, $user, $plainToken);

        return $this->json(['message' => 'If the address is available, a confirmation email has been sent.']);
    }

    /**
     * Public endpoint — anyone with a valid token can complete the
     * change. Updates the user's email, then notifies the *old*
     * address with revert + password-reset links.
     */
    #[Route('/auth/confirm-email-change', name: 'auth_confirm_email_change', methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $plainToken = (string) ($data['token'] ?? '');

        if ('' === $plainToken) {
            return $this->json(['error' => 'Token is required.'], 400);
        }

        $changeRequest = $this->requests->findByConfirmTokenHash(hash('sha256', $plainToken));
        if (null === $changeRequest || !$changeRequest->isConfirmable()) {
            return $this->json(['error' => 'Invalid or expired token.'], 400);
        }

        // Re-check email uniqueness — someone else may have claimed it.
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $changeRequest->getNewEmail()]);
        if (null !== $existing && $existing !== $changeRequest->getUser()) {
            $changeRequest->cancel();
            $this->em->flush();
            return $this->json(['error' => 'That email is no longer available.'], 409);
        }

        $user = $changeRequest->getUser();
        $user->setEmail($changeRequest->getNewEmail());

        $revertToken = bin2hex(random_bytes(32));
        $revertHash = hash('sha256', $revertToken);
        $revertExpiresAt = new \DateTimeImmutable(sprintf('+%d hours', self::REVERT_TOKEN_TTL_HOURS));
        $changeRequest->markConfirmed($revertHash, $revertExpiresAt);

        $this->em->flush();

        $this->sendRevertNotice($changeRequest, $revertToken);

        return $this->json([
            'message' => 'Email updated.',
            'newEmail' => $changeRequest->getNewEmail(),
        ]);
    }

    /**
     * Public endpoint — bearer of a valid revert token can roll the
     * user's email back to its previous value. No re-authentication is
     * required: the threat model is "the change wasn't me", and the
     * person who got the notice email controlled the old address.
     */
    #[Route('/auth/revert-email-change', name: 'auth_revert_email_change', methods: ['POST'])]
    public function revert(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $plainToken = (string) ($data['token'] ?? '');

        if ('' === $plainToken) {
            return $this->json(['error' => 'Token is required.'], 400);
        }

        $changeRequest = $this->requests->findByRevertTokenHash(hash('sha256', $plainToken));
        if (null === $changeRequest || !$changeRequest->isRevertable()) {
            return $this->json(['error' => 'Invalid or expired token.'], 400);
        }

        // Re-check old-email uniqueness — extremely unlikely but possible
        // if someone else registered the freed-up address in the meantime.
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $changeRequest->getOldEmail()]);
        if (null !== $existing && $existing !== $changeRequest->getUser()) {
            return $this->json([
                'error' => 'Your previous email is no longer available. Please request a fresh change from your account page.',
            ], 409);
        }

        $user = $changeRequest->getUser();
        $user->setEmail($changeRequest->getOldEmail());
        $changeRequest->markReverted();
        $this->em->flush();

        return $this->json([
            'message' => 'Email reverted.',
            'restoredEmail' => $changeRequest->getOldEmail(),
        ]);
    }

    private function sendConfirmEmail(string $newEmail, User $user, string $plainToken): void
    {
        $confirmUrl = sprintf(
            '%s/confirm-email-change?token=%s',
            rtrim($this->frontendUrl, '/'),
            $plainToken,
        );
        $from = $this->mailerFrom ?: 'no-reply@aura.test';

        $email = (new Email())
            ->from($from)
            ->to($newEmail)
            ->subject('Confirm your new Aura email address')
            ->text(sprintf(
                "Hi,\n\n"
                . "We received a request to change the email on your Aura account from %s to %s. "
                . "Click the link below to confirm:\n\n%s\n\n"
                . "This link expires in %d hour(s). If you did not request this change, you can safely ignore this email — your account email won't change unless you click the link.\n\n— Aura",
                $user->getEmail(),
                $newEmail,
                $confirmUrl,
                self::CONFIRM_TOKEN_TTL_HOURS,
            ))
            ->html(sprintf(
                '<p>Hi,</p>'
                . '<p>We received a request to change the email on your Aura account from <strong>%1$s</strong> to <strong>%2$s</strong>. Click the link below to confirm:</p>'
                . '<p><a href="%3$s">%3$s</a></p>'
                . '<p>This link expires in %4$d hour(s). If you did not request this change, you can safely ignore this email &mdash; your account email won&rsquo;t change unless you click the link.</p>'
                . '<p>&mdash; Aura</p>',
                htmlspecialchars($user->getEmail()),
                htmlspecialchars($newEmail),
                htmlspecialchars($confirmUrl),
                self::CONFIRM_TOKEN_TTL_HOURS,
            ));

        $this->mailer->send($email);
    }

    private function sendRevertNotice(EmailChangeRequest $changeRequest, string $revertToken): void
    {
        $revertUrl = sprintf(
            '%s/revert-email-change?token=%s',
            rtrim($this->frontendUrl, '/'),
            $revertToken,
        );
        $resetUrl = sprintf('%s/forgot-password', rtrim($this->frontendUrl, '/'));
        $from = $this->mailerFrom ?: 'no-reply@aura.test';

        $email = (new Email())
            ->from($from)
            ->to($changeRequest->getOldEmail())
            ->subject('Your Aura email address was changed')
            ->text(sprintf(
                "Hi,\n\n"
                . "The email on your Aura account was changed from %s to %s.\n\n"
                . "If this was you, no action is needed.\n\n"
                . "If it wasn't, you can undo the change here:\n%s\n\n"
                . "And reset your password to lock down the account:\n%s\n\n"
                . "The undo link expires in %d days.\n\n— Aura",
                $changeRequest->getOldEmail(),
                $changeRequest->getNewEmail(),
                $revertUrl,
                $resetUrl,
                (int) (self::REVERT_TOKEN_TTL_HOURS / 24),
            ))
            ->html(sprintf(
                '<p>Hi,</p>'
                . '<p>The email on your Aura account was changed from <strong>%1$s</strong> to <strong>%2$s</strong>.</p>'
                . '<p>If this was you, no action is needed.</p>'
                . '<p>If it wasn&rsquo;t, you can <a href="%3$s">undo the change</a> and then <a href="%4$s">reset your password</a> to lock the account down.</p>'
                . '<p>The undo link expires in %5$d days.</p>'
                . '<p>&mdash; Aura</p>',
                htmlspecialchars($changeRequest->getOldEmail()),
                htmlspecialchars($changeRequest->getNewEmail()),
                htmlspecialchars($revertUrl),
                htmlspecialchars($resetUrl),
                (int) (self::REVERT_TOKEN_TTL_HOURS / 24),
            ));

        $this->mailer->send($email);
    }
}
