<?php

namespace App\Service;

use App\Entity\Space;
use App\Entity\TimesheetSubmission;
use App\Service\Mail\MailDispatcher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Emails for the timesheet approval flow (#654): submission → the space's
 * admins (the reviewers); approve/reject → the member who submitted.
 * Best-effort, no-ops without an email on file.
 */
final class TimesheetMailer
{
    public function __construct(
        private readonly MailDispatcher $mail,
    ) {
    }

    public function sendSubmitted(TimesheetSubmission $submission): void
    {
        $space = $submission->getSpace();
        $member = $submission->getUser();
        $weekStart = $submission->getWeekStart();
        if (null === $space || null === $member || null === $weekStart) {
            return;
        }

        $name = trim($member->getGivenName() . ' ' . $member->getFamilyName());
        foreach ($space->getUserMemberships() as $membership) {
            if (Space::ROLE_ADMIN !== $membership->getRole()) {
                continue;
            }
            $admin = $membership->getUser();
            // Submitting your own week (as an admin) shouldn't self-notify.
            if (null === $admin || true === $admin->getId()?->equals($member->getId())) {
                continue;
            }
            $to = $admin->getEmail();
            if ('' === trim($to)) {
                continue;
            }

            $email = (new TemplatedEmail())
                ->to($to)
                ->subject(sprintf(
                    '%s submitted their timesheet for the week of %s',
                    '' !== $name ? $name : $member->getEmail(),
                    $weekStart->format('M j'),
                ))
                ->htmlTemplate('emails/timesheet_submitted.html.twig')
                ->textTemplate('emails/timesheet_submitted.txt.twig')
                ->context([
                    'submission' => $submission,
                    'memberName' => '' !== $name ? $name : $member->getEmail(),
                    'approvalsUrl' => $this->mail->frontendLink('/approvals'),
                ]);
            $this->mail->send($email);
        }
    }

    public function sendDecision(TimesheetSubmission $submission): void
    {
        $member = $submission->getUser();
        $weekStart = $submission->getWeekStart();
        if (null === $member || null === $weekStart) {
            return;
        }
        $to = $member->getEmail();
        if ('' === trim($to)) {
            return;
        }

        $approved = TimesheetSubmission::STATUS_APPROVED === $submission->getStatus();
        $email = (new TemplatedEmail())
            ->to($to)
            ->subject(sprintf(
                'Your timesheet for the week of %s was %s',
                $weekStart->format('M j'),
                $approved ? 'approved' : 'rejected',
            ))
            ->htmlTemplate('emails/timesheet_decision.html.twig')
            ->textTemplate('emails/timesheet_decision.txt.twig')
            ->context([
                'submission' => $submission,
                'approved' => $approved,
                'timeUrl' => $this->mail->frontendLink('/time'),
            ]);
        $this->mail->send($email);
    }
}
