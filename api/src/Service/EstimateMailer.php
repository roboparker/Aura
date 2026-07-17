<?php

namespace App\Service;

use App\Entity\Estimate;
use App\Service\Mail\MailDispatcher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Emails for the estimate lifecycle (#652): the "here's your estimate" link
 * when it's sent (public accept/decline page, plaintext token only ever in
 * the email), and the accepted/declined notice back to the estimate's
 * creator when the client decides. No-ops without an email on file.
 */
final class EstimateMailer
{
    public function __construct(
        private readonly MailDispatcher $mail,
    ) {
    }

    public function sendEstimate(Estimate $estimate, string $plainToken): void
    {
        $to = $estimate->getClient()?->getEmail();
        if (null === $to || '' === trim($to)) {
            return;
        }

        $from = $estimate->getSpace()?->getName() ?? 'Madori';
        $number = $estimate->getNumber() ?? 'estimate';

        $email = (new TemplatedEmail())
            ->to($to)
            ->subject(sprintf('%s from %s', ucfirst($number), $from))
            ->htmlTemplate('emails/estimate.html.twig')
            ->textTemplate('emails/estimate.txt.twig')
            ->context([
                'estimate' => $estimate,
                'client' => $estimate->getClient(),
                'fromName' => $from,
                'viewUrl' => $this->mail->frontendLink('/e/' . $plainToken),
            ]);

        $this->mail->send($email);
    }

    public function sendDecision(Estimate $estimate): void
    {
        $to = $estimate->getCreatedBy()?->getEmail();
        if (null === $to || '' === trim($to)) {
            return;
        }

        $accepted = Estimate::STATUS_ACCEPTED === $estimate->getStatus();
        $number = $estimate->getNumber() ?? 'Your estimate';

        $email = (new TemplatedEmail())
            ->to($to)
            ->subject(sprintf('%s was %s', ucfirst($number), $accepted ? 'accepted' : 'declined'))
            ->htmlTemplate('emails/estimate_decision.html.twig')
            ->textTemplate('emails/estimate_decision.txt.twig')
            ->context([
                'estimate' => $estimate,
                'accepted' => $accepted,
                'estimatesUrl' => $this->mail->frontendLink('/estimates'),
            ]);

        $this->mail->send($email);
    }
}
