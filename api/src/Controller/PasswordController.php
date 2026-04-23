<?php

namespace App\Controller;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\PasswordResetTokenRepository;
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

class PasswordController extends AbstractController
{
    private const RESET_TOKEN_TTL_HOURS = 1;
    private const MIN_PASSWORD_LENGTH = 6;

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private PasswordResetTokenRepository $tokenRepository,
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

        $data = json_decode($request->getContent(), true) ?? [];
        $currentPassword = (string) ($data['currentPassword'] ?? '');
        $newPassword = (string) ($data['newPassword'] ?? '');

        if (!$this->hasher->isPasswordValid($user, $currentPassword)) {
            return $this->json(['error' => 'Current password is incorrect.'], 400);
        }

        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return $this->json([
                'error' => sprintf('New password must be at least %d characters.', self::MIN_PASSWORD_LENGTH),
            ], 422);
        }

        if ($currentPassword === $newPassword) {
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
        $data = json_decode($request->getContent(), true) ?? [];
        $email = (string) ($data['email'] ?? '');

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
        $data = json_decode($request->getContent(), true) ?? [];
        $plainToken = (string) ($data['token'] ?? '');
        $newPassword = (string) ($data['newPassword'] ?? '');

        if ('' === $plainToken) {
            return $this->json(['error' => 'Token is required.'], 400);
        }

        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return $this->json([
                'error' => sprintf('Password must be at least %d characters.', self::MIN_PASSWORD_LENGTH),
            ], 422);
        }

        $tokenHash = hash('sha256', $plainToken);
        $resetToken = $this->tokenRepository->findByTokenHash($tokenHash);

        if (null === $resetToken || !$resetToken->isValid()) {
            return $this->json(['error' => 'Invalid or expired token.'], 400);
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
        $from = $this->mailerFrom ?: 'no-reply@aura.test';

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
}
