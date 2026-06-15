<?php

namespace App\Service;

use App\Entity\UserInvite;
use App\Service\Mail\MailDispatcher;
use Symfony\Component\Mime\Email;

/**
 * Renders and sends the "you've been invited to Madori" email so the
 * group-invite and space-invite flows share one body. The single
 * UserInvite per email may carry both group and space attachments,
 * and the message lists every one currently attached so the
 * recipient sees the full picture in one place.
 *
 * Token rotation is the caller's responsibility — every send is
 * authoritative for that invitee, and any older email's link stops
 * working as soon as a fresh one is dispatched.
 */
final class InviteMailer
{
    public function __construct(
        private readonly MailDispatcher $mail,
    ) {
    }

    public function sendSignupLink(UserInvite $invite, string $plainToken, int $ttlDays): void
    {
        $signupUrl = $this->mail->frontendLink('/signup?invite=' . $plainToken);

        $targets = $this->collectTargetLabels($invite);
        $contextLine = $this->formatContextLine($targets);

        $message = (new Email())
            ->to($invite->getEmail())
            ->subject('You\'ve been invited to join Madori')
            ->text(sprintf(
                "Hi,\n\nYou've been invited to join %s on Madori. Create your account to accept:\n\n%s\n\nThis invitation expires in %d days. If you weren't expecting this, you can safely ignore the email.\n\n— Madori",
                $contextLine,
                $signupUrl,
                $ttlDays,
            ))
            ->html(sprintf(
                '<p>Hi,</p><p>You\'ve been invited to join %1$s on Madori. Create your account to accept:</p><p><a href="%2$s">%2$s</a></p><p>This invitation expires in %3$d days. If you weren\'t expecting this, you can safely ignore the email.</p><p>— Madori</p>',
                htmlspecialchars($contextLine),
                htmlspecialchars($signupUrl),
                $ttlDays,
            ));

        $this->mail->send($message);
    }

    /**
     * Names of every group and space currently attached to the
     * invite, qualified so the recipient can tell them apart at a
     * glance — `group "X"` vs `space "Y"`.
     *
     * @return list<string>
     */
    private function collectTargetLabels(UserInvite $invite): array
    {
        $labels = [];
        foreach ($invite->getGroupInvites() as $gi) {
            $labels[] = sprintf('group "%s"', $gi->getGroup()->getTitle());
        }
        foreach ($invite->getSpaceInvites() as $si) {
            $labels[] = sprintf('space "%s"', $si->getSpace()->getName());
        }
        return $labels;
    }

    /**
     * Joins the labels into prose suitable for the email body. Empty
     * input falls back to the generic phrase so a stale invite with
     * no attachments still produces a valid email.
     *
     * @param list<string> $labels
     */
    private function formatContextLine(array $labels): string
    {
        return match (count($labels)) {
            0 => 'an Madori workspace',
            1 => 'the ' . $labels[0],
            2 => 'the ' . $labels[0] . ' and the ' . $labels[1],
            default => 'these workspaces: ' . implode(', ', $labels),
        };
    }
}
