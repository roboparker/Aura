<?php

declare(strict_types=1);

namespace App\Service;

use App\Deletion\SoftDeletable;
use App\Deletion\SoftDeletionService;
use App\Entity\User;
use App\Service\Mail\MailDispatcher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * "A site administrator deleted your X."
 *
 * Separate from {@see DeletionNoticeMailer} because the two say opposite
 * things: that one is reassurance with an undo link, this one is a
 * notification about something the recipient didn't choose and — when it was
 * immediate — cannot reverse. Offering a restore link here would be a lie.
 *
 * Sent only when the acting admin opts in, per action.
 */
final class AdminDeletionNoticeMailer
{
    public function __construct(private MailDispatcher $dispatcher)
    {
    }

    public function sendAdminDeletionNotice(
        User $recipient,
        SoftDeletable $target,
        bool $immediate,
        string $reason,
    ): void {
        $noun = match ($target->deletionTargetType()) {
            SoftDeletionService::TYPE_ORGANIZATION => 'organization',
            SoftDeletionService::TYPE_SPACE => 'space',
            default => 'account',
        };

        $this->dispatcher->send(
            (new TemplatedEmail())
                ->to($recipient->getEmail())
                ->subject(sprintf(
                    'Your %s "%s" has been %s',
                    $noun,
                    $target->deletionLabel(),
                    $immediate ? 'deleted' : 'scheduled for deletion',
                ))
                ->htmlTemplate('emails/admin_deletion.html.twig')
                ->textTemplate('emails/admin_deletion.txt.twig')
                ->context([
                    'recipientName' => $recipient->getGivenName(),
                    'noun' => $noun,
                    'label' => $target->deletionLabel(),
                    'immediate' => $immediate,
                    'isAccount' => SoftDeletionService::TYPE_ACCOUNT === $target->deletionTargetType(),
                    'reason' => $reason,
                    'purgeAfter' => $target->getPurgeAfter(),
                ]),
        );
    }
}
