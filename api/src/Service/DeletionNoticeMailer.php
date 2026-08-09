<?php

declare(strict_types=1);

namespace App\Service;

use App\Deletion\SoftDeletable;
use App\Deletion\SoftDeletionService;
use App\Entity\User;
use App\Service\Mail\MailDispatcher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * "X is scheduled for deletion — here's how to undo it."
 *
 * One template for all three target types rather than three near-identical
 * pairs: the only thing that varies is a noun and the label, and three copies
 * would drift the moment one is edited. The restore link is the whole point of
 * the email, so it leads.
 *
 * Sending rides the normal async mail queue (every `MailerInterface::send()` is
 * routed to Messenger), so a slow SMTP hop never blocks the delete request.
 */
final class DeletionNoticeMailer
{
    public function __construct(private MailDispatcher $dispatcher)
    {
    }

    public function sendDeletionScheduled(
        User $recipient,
        SoftDeletable $target,
        string $plainToken,
        \DateTimeImmutable $purgeAfter,
    ): void {
        $noun = self::noun($target->deletionTargetType());
        $email = (new TemplatedEmail())
            ->to($recipient->getEmail())
            ->subject(sprintf('Your %s "%s" is scheduled for deletion', $noun, $target->deletionLabel()))
            ->htmlTemplate('emails/deletion_scheduled.html.twig')
            ->textTemplate('emails/deletion_scheduled.txt.twig')
            ->context([
                'recipientName' => $recipient->getGivenName(),
                'noun' => $noun,
                'label' => $target->deletionLabel(),
                'isAccount' => SoftDeletionService::TYPE_ACCOUNT === $target->deletionTargetType(),
                'purgeAfter' => $purgeAfter,
                'restoreUrl' => $this->dispatcher->frontendLink('/restore/' . $plainToken),
            ]);

        // Through MailDispatcher, which stamps the From address — without it
        // the transport rejects the message and the only route back from a
        // deletion never arrives.
        $this->dispatcher->send($email);
    }

    private static function noun(string $targetType): string
    {
        return match ($targetType) {
            SoftDeletionService::TYPE_ORGANIZATION => 'organization',
            SoftDeletionService::TYPE_SPACE => 'space',
            default => 'account',
        };
    }
}
