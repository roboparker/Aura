<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Service\Mail\MailDispatcher;
use Symfony\Component\Mime\Email;

/**
 * Notification emails for the 2FA-recovery path. Three distinct messages:
 *
 *  - sendBackupCodeUsed(): fires the moment a backup code is accepted at
 *    login, so a legitimate user whose code was stolen sees the alert
 *    even before the attacker can act on it.
 *  - sendDisabled(): 2FA was turned off via the recovery interstitial.
 *  - sendReconfigured(): a new authenticator was enrolled via the
 *    recovery interstitial (TOTP secret rotated, recovery codes
 *    regenerated).
 *
 * Sent best-effort — the auth/setup flow shouldn't fail if SMTP is down.
 * Callers wrap the dispatch in a try/catch so a mail outage doesn't deny
 * the user access to their own account.
 */
final class TwoFactorRecoveryMailer
{
    public function __construct(
        private readonly MailDispatcher $mail,
    ) {
    }

    public function sendBackupCodeUsed(User $user): void
    {
        $accountUrl = $this->mail->frontendLink('/account');
        $this->dispatch(
            $user,
            'A recovery code was used on your Madori account',
            sprintf(
                "Hi,\n\nA recovery code was just used to sign in to your Madori account (%s).\n\nIf this was you, you'll be prompted to either re-enroll a new authenticator or turn off two-factor authentication on your next page load. If this wasn't you, sign in and change your password immediately:\n\n%s\n\n— Madori",
                $user->getEmail(),
                $accountUrl,
            ),
            sprintf(
                '<p>Hi,</p><p>A recovery code was just used to sign in to your Madori account (%1$s).</p><p>If this was you, you\'ll be prompted to either re-enroll a new authenticator or turn off two-factor authentication on your next page load.</p><p>If this wasn\'t you, <a href="%2$s">sign in and change your password immediately</a>.</p><p>— Madori</p>',
                htmlspecialchars($user->getEmail()),
                htmlspecialchars($accountUrl),
            ),
        );
    }

    public function sendDisabled(User $user): void
    {
        $accountUrl = $this->mail->frontendLink('/account');
        $this->dispatch(
            $user,
            'Two-factor authentication was disabled',
            sprintf(
                "Hi,\n\nTwo-factor authentication was just disabled on your Madori account (%s).\n\nIf this wasn't you, sign in and change your password immediately:\n\n%s\n\n— Madori",
                $user->getEmail(),
                $accountUrl,
            ),
            sprintf(
                '<p>Hi,</p><p>Two-factor authentication was just disabled on your Madori account (%1$s).</p><p>If this wasn\'t you, <a href="%2$s">sign in and change your password immediately</a>.</p><p>— Madori</p>',
                htmlspecialchars($user->getEmail()),
                htmlspecialchars($accountUrl),
            ),
        );
    }

    public function sendReconfigured(User $user): void
    {
        $accountUrl = $this->mail->frontendLink('/account');
        $this->dispatch(
            $user,
            'A new authenticator was enrolled for your account',
            sprintf(
                "Hi,\n\nA new two-factor authenticator was just enrolled on your Madori account (%s). Your previous recovery codes are no longer valid — you'll see a fresh set in your security settings.\n\nIf this wasn't you, sign in and change your password immediately:\n\n%s\n\n— Madori",
                $user->getEmail(),
                $accountUrl,
            ),
            sprintf(
                '<p>Hi,</p><p>A new two-factor authenticator was just enrolled on your Madori account (%1$s). Your previous recovery codes are no longer valid — you\'ll see a fresh set in your security settings.</p><p>If this wasn\'t you, <a href="%2$s">sign in and change your password immediately</a>.</p><p>— Madori</p>',
                htmlspecialchars($user->getEmail()),
                htmlspecialchars($accountUrl),
            ),
        );
    }

    private function dispatch(User $user, string $subject, string $text, string $html): void
    {
        $to = $user->getEmail();
        if ('' === $to) {
            return;
        }

        $message = (new Email())
            ->to($to)
            ->subject($subject)
            ->text($text)
            ->html($html);

        $this->mail->send($message);
    }
}
