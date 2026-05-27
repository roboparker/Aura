<?php

namespace App\Controller;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\PasswordResetTokenRepository;
use App\Service\SensitiveActionVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Constraints\PasswordStrengthValidator;

class PasswordController extends AbstractController
{
    private const RESET_TOKEN_TTL_HOURS = 1;
    // Mirrors the `Assert\Length(min: 8)` on `App\Entity\User::$plainPassword`
    // so signup, password-change, and password-reset all gate at the same
    // floor. NIST SP 800-63B: length is the primary factor, complexity
    // rules are user-hostile.
    private const MIN_PASSWORD_LENGTH = 8;
    // Pairs with the `Assert\PasswordStrength` on the User entity; the
    // signup form runs the validator stack so it picks that up via
    // attribute, but change-password and reset-password are plain
    // controller actions and re-check by hand.
    private const MIN_PASSWORD_STRENGTH = PasswordStrength::STRENGTH_MEDIUM;

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private PasswordResetTokenRepository $tokenRepository,
        private SensitiveActionVerifier $verifier,
        private MailerInterface $mailer,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
    }

    #[Route('/auth/change-password', name: 'auth_change_password', methods: ['POST'])]
    public function changePassword(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $data = $request->toArray();
        $newPassword = self::stringField($data, 'newPassword');

        // Step-up confirmation: TOTP when 2FA is on, current password
        // otherwise. Owning the cookie alone isn't enough to silently
        // rotate the password out from under the legitimate owner.
        $err = $this->verifier->verify($user, $data);
        if (null !== $err) {
            return $this->json(['error' => $err[1]], $err[0]);
        }

        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return $this->json([
                'error' => sprintf('New password must be at least %d characters.', self::MIN_PASSWORD_LENGTH),
            ], 422);
        }

        if (PasswordStrengthValidator::estimateStrength($newPassword) < self::MIN_PASSWORD_STRENGTH) {
            return $this->json([
                'error' => 'This password is too easy to guess — try mixing in more characters or making it longer.',
            ], 422);
        }

        if ($this->hasher->isPasswordValid($user, $newPassword)) {
            return $this->json([
                'error' => 'New password must be different from current password.',
            ], 422);
        }

        $user->setPassword($this->hasher->hashPassword($user, $newPassword));
        $this->em->flush();

        return $this->json(['message' => 'Password updated successfully.']);
    }

    #[Route('/auth/forgot-password', name: 'auth_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $email = self::stringField($data, 'email');

        // Always return 200 to prevent email enumeration
        $response = $this->json([
            'message' => 'If an account exists for that email, a reset link has been sent.',
        ]);

        if ('' === $email) {
            return $response;
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $user) {
            return $response;
        }

        // Invalidate prior outstanding tokens, then issue a fresh one
        $this->tokenRepository->invalidateAllForUser($user);

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = new \DateTimeImmutable(sprintf('+%d hours', self::RESET_TOKEN_TTL_HOURS));

        $resetToken = new PasswordResetToken($user, $tokenHash, $expiresAt);
        $this->em->persist($resetToken);
        $this->em->flush();

        $this->sendResetEmail($user, $plainToken);

        return $response;
    }

    #[Route('/auth/reset-password', name: 'auth_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $plainToken = self::stringField($data, 'token');
        $newPassword = self::stringField($data, 'newPassword');

        if ('' === $plainToken) {
            return $this->json(['error' => 'Token is required.'], 400);
        }

        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return $this->json([
                'error' => sprintf('Password must be at least %d characters.', self::MIN_PASSWORD_LENGTH),
            ], 422);
        }

        if (PasswordStrengthValidator::estimateStrength($newPassword) < self::MIN_PASSWORD_STRENGTH) {
            return $this->json([
                'error' => 'This password is too easy to guess — try mixing in more characters or making it longer.',
            ], 422);
        }

        $tokenHash = hash('sha256', $plainToken);
        $resetToken = $this->tokenRepository->findByTokenHash($tokenHash);

        // Distinguish the three rejection cases so the PWA can show the
        // right "this link can't be used" card. All three still return
        // 400 — the `code` field is the contract the client switches on.
        if (null === $resetToken) {
            return $this->json([
                'error' => 'This reset link is invalid.',
                'code' => 'token_invalid',
            ], 400);
        }
        if (null !== $resetToken->getUsedAt()) {
            return $this->json([
                'error' => 'This reset link has already been used.',
                'code' => 'token_used',
            ], 400);
        }
        if (!$resetToken->isValid()) {
            return $this->json([
                'error' => 'This reset link has expired.',
                'code' => 'token_expired',
            ], 400);
        }

        $user = $resetToken->getUser();
        $user->setPassword($this->hasher->hashPassword($user, $newPassword));
        $resetToken->markUsed();
        $this->em->flush();

        return $this->json(['message' => 'Password reset successfully.']);
    }

    private function sendResetEmail(User $user, string $plainToken): void
    {
        $resetUrl = sprintf('%s/reset-password?token=%s', rtrim($this->frontendUrl, '/'), $plainToken);
        $from = (null !== $this->mailerFrom && '' !== $this->mailerFrom) ? $this->mailerFrom : 'no-reply@aura.test';

        $email = (new Email())
            ->from($from)
            ->to($user->getEmail())
            ->subject('Reset your Aura password')
            ->text(sprintf(
                "Hi,\n\nWe received a request to reset your Aura password. Click the link below to set a new password:\n\n%s\n\nThis link expires in %d hour(s). If you did not request a password reset, you can safely ignore this email.\n\n— Aura",
                $resetUrl,
                self::RESET_TOKEN_TTL_HOURS,
            ))
            ->html(sprintf(
                '<p>Hi,</p><p>We received a request to reset your Aura password. Click the link below to set a new password:</p><p><a href="%1$s">%1$s</a></p><p>This link expires in %2$d hour(s). If you did not request a password reset, you can safely ignore this email.</p><p>— Aura</p>',
                htmlspecialchars($resetUrl),
                self::RESET_TOKEN_TTL_HOURS,
            ));

        $this->mailer->send($email);
    }

    /**
     * Plucks a string field from a JSON body, defaulting to '' for
     * missing / non-string values. Avoids the cast.string violation
     * that comes with `(string) ($data[$key] ?? '')`.
     *
     * @param array<int|string, mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : '';
    }
}
