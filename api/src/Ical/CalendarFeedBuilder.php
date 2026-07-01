<?php

namespace App\Ical;

use App\Entity\Task;
use App\Entity\User;
use App\Service\RecurrenceCalculator;
use App\Service\ReminderScheduler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Renders a user's due-dated tasks as an RFC 5545 iCalendar document for the
 * read-only calendar feed (#calendar-sync). Scope is the tasks the user owns
 * OR is assigned to and that carry a due date.
 *
 * Times are rendered in the user's timezone preference (a single VTIMEZONE is
 * emitted; UTC users get plain `Z` times). Each non-recurring task — and each
 * completed recurring task, whose live successor carries the rest of the
 * series — is one VEVENT on its due date. A *live* recurring task (a rule with
 * no completion) is emitted as a single VEVENT carrying an RRULE when its rule
 * maps cleanly, otherwise expanded into individual dated VEVENTs over a bounded
 * upcoming window.
 */
final class CalendarFeedBuilder
{
    /** Bounded expansion window for rules that don't map to a single RRULE. */
    private const EXPANSION_BACK_DAYS = 30;
    private const EXPANSION_FORWARD_DAYS = 400;
    private const MAX_OCCURRENCES = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IcalWriter $writer,
        private readonly VTimezoneBuilder $vtimezone,
        private readonly RecurrenceCalculator $recurrence,
        private readonly RecurrenceRuleToRrule $rrule,
        private readonly ReminderScheduler $reminders,
    ) {
    }

    public function build(User $user, string $baseUrl): string
    {
        $tz = $this->resolveTimezone($user);
        $now = new \DateTimeImmutable('now');
        $domain = $this->domainFor($baseUrl);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            $this->writer->line('PRODID', '-//Madori//Calendar Feed//EN'),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            $this->writer->line('X-WR-CALNAME', $this->writer->escapeText($this->calendarName($user))),
            $this->writer->line('X-WR-TIMEZONE', $tz->getName()),
        ];

        $vtz = $this->vtimezone->build(
            $tz,
            $now->modify('-1 year'),
            $now->modify('+5 years'),
        );
        if (null !== $vtz) {
            $lines = array_merge($lines, $vtz);
        }

        foreach ($this->fetchTasks($user) as $task) {
            $lines = array_merge($lines, $this->veventsForTask($task, $tz, $now, $baseUrl, $domain));
        }

        $lines[] = 'END:VCALENDAR';

        return $this->writer->document($lines);
    }

    /**
     * The VEVENT(s) for one task.
     *
     * @return list<string>
     */
    private function veventsForTask(
        Task $task,
        \DateTimeZone $tz,
        \DateTimeImmutable $now,
        string $baseUrl,
        string $domain,
    ): array {
        $due = $task->getDueDate();
        if (null === $due) {
            return [];
        }

        $rule = $task->getRecurrenceRule();
        $isLiveSeries = null !== $rule && null === $task->getCompletedOn();

        if (!$isLiveSeries) {
            return $this->vevent($task, $due, $tz, $now, $baseUrl, $domain, null);
        }

        // Live recurring series ($rule is non-null here): prefer a single
        // VEVENT + RRULE.
        $rruleLine = $this->rrule->map($due, $rule, $tz);
        if (null !== $rruleLine) {
            return $this->vevent($task, $due, $tz, $now, $baseUrl, $domain, $rruleLine);
        }

        // Fall back to expanding a bounded upcoming window as dated VEVENTs.
        $rangeStart = $now->modify('-' . self::EXPANSION_BACK_DAYS . ' days');
        $rangeEnd = $now->modify('+' . self::EXPANSION_FORWARD_DAYS . ' days');
        $occurrences = $this->recurrence->occurrencesInRange(
            $due,
            $rule,
            $rangeStart,
            $rangeEnd,
            $task->getRecurrenceExceptions(),
        );

        $lines = [];
        $count = 0;
        foreach ($occurrences as $occurrence) {
            if ($count >= self::MAX_OCCURRENCES) {
                break;
            }
            $lines = array_merge(
                $lines,
                $this->vevent($task, $occurrence, $tz, $now, $baseUrl, $domain, null, $occurrence),
            );
            ++$count;
        }

        return $lines;
    }

    /**
     * A single VEVENT. `$rrule`, when present, is the RRULE line for a
     * recurring series; `$occurrenceKey`, when present, disambiguates the UID
     * of one expanded occurrence of a series.
     *
     * @return list<string>
     */
    private function vevent(
        Task $task,
        \DateTimeInterface $start,
        \DateTimeZone $tz,
        \DateTimeImmutable $now,
        string $baseUrl,
        string $domain,
        ?string $rrule,
        ?\DateTimeInterface $occurrenceKey = null,
    ): array {
        $lines = ['BEGIN:VEVENT'];
        $lines[] = $this->writer->line('UID', $this->uid($task, $domain, $occurrenceKey));
        $lines[] = $this->writer->line('DTSTAMP', $this->writer->formatUtc($now));
        $lines[] = $this->dateTimeLine('DTSTART', $start, $tz);
        $lines[] = $this->writer->line('SUMMARY', $this->writer->escapeText($task->getTitle()));
        $lines[] = $this->writer->line(
            'DESCRIPTION',
            $this->writer->escapeText($this->descriptionFor($task, $baseUrl)),
        );
        $lines[] = $this->writer->line('URL', $this->deepLink($task, $baseUrl));

        if (null !== $task->getCompletedOn()) {
            $lines[] = 'STATUS:COMPLETED';
        }

        if (null !== $rrule) {
            $lines[] = $rrule;
            foreach ($task->getRecurrenceExceptions() as $exception) {
                $exdate = $this->exdateLine($exception, $start, $tz);
                if (null !== $exdate) {
                    $lines[] = $exdate;
                }
            }
        }

        $lines = array_merge($lines, $this->valarms($task));

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * VALARM sub-components for a task's reminders. Relative reminders map to
     * a DTSTART-relative TRIGGER (`-PT15M`), which iCal applies to each
     * recurrence; absolute reminders map to a fixed UTC DATE-TIME trigger.
     * "Repeat daily until done" is an Aura-server concept with no iCal
     * equivalent, so only the base trigger is emitted.
     *
     * @return list<string>
     */
    private function valarms(Task $task): array
    {
        $reminders = $task->getReminders();
        if (null === $reminders) {
            return [];
        }

        $lines = [];
        foreach ($reminders as $reminder) {
            if (!is_array($reminder) || !$this->reminders->isValidShape($reminder)) {
                continue;
            }
            $trigger = $this->triggerLine($reminder);
            if (null === $trigger) {
                continue;
            }
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = $this->writer->line('DESCRIPTION', $this->writer->escapeText($task->getTitle()));
            $lines[] = $trigger;
            $lines[] = 'END:VALARM';
        }

        return $lines;
    }

    /**
     * The TRIGGER content line for one reminder, or null when it can't be
     * expressed (e.g. an unparseable absolute time).
     *
     * @param array<array-key, mixed> $reminder
     */
    private function triggerLine(array $reminder): ?string
    {
        $type = $reminder['type'] ?? null;

        if ('relative' === $type) {
            $value = is_int($reminder['value'] ?? null) ? $reminder['value'] : 0;
            $unit = is_string($reminder['unit'] ?? null) ? $reminder['unit'] : 'minutes';

            return $this->writer->line('TRIGGER', $this->relativeDuration($value, $unit));
        }

        if ('absolute' === $type) {
            $at = is_string($reminder['at'] ?? null) ? $reminder['at'] : '';
            try {
                $when = new \DateTimeImmutable($at);
            } catch (\Exception) {
                return null;
            }

            return $this->writer->line(
                'TRIGGER',
                $this->writer->formatUtc($when),
                ['VALUE' => 'DATE-TIME'],
            );
        }

        return null;
    }

    /** A negative ISO-8601 duration relative to the event start ("before due"). */
    private function relativeDuration(int $value, string $unit): string
    {
        if (0 === $value) {
            return '-PT0M';
        }

        return match ($unit) {
            'hours' => '-PT' . $value . 'H',
            'days' => '-P' . $value . 'D',
            default => '-PT' . $value . 'M',
        };
    }

    /**
     * Emit a DATE-TIME property in the user's zone (TZID + floating local
     * time), or as UTC (`Z`) when the zone is UTC.
     */
    private function dateTimeLine(string $name, \DateTimeInterface $when, \DateTimeZone $tz): string
    {
        if ('UTC' === $tz->getName()) {
            return $this->writer->line($name, $this->writer->formatUtc($when));
        }

        return $this->writer->line(
            $name,
            $this->writer->formatLocal($when, $tz),
            ['TZID' => $tz->getName()],
        );
    }

    /**
     * An EXDATE for one `Y-m-d` exception, timed at the series' clock time in
     * the user's zone. Returns null when the exception can't be parsed.
     */
    private function exdateLine(string $exception, \DateTimeInterface $start, \DateTimeZone $tz): ?string
    {
        $clock = \DateTimeImmutable::createFromInterface($start)->setTimezone($tz);
        $local = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $exception . ' ' . $clock->format('H:i:s'),
            $tz,
        );
        if (false === $local) {
            return null;
        }

        return $this->dateTimeLine('EXDATE', $local, $tz);
    }

    private function uid(Task $task, string $domain, ?\DateTimeInterface $occurrenceKey): string
    {
        $base = 'task-' . $task->getId();
        if (null !== $occurrenceKey) {
            $base .= '-' . $occurrenceKey->format('Ymd');
        }

        return $base . '@' . $domain;
    }

    private function descriptionFor(Task $task, string $baseUrl): string
    {
        $parts = [];
        $body = $task->getDescription();
        if (null !== $body && '' !== trim($body)) {
            $parts[] = trim($body);
        }
        // Also surface the deep link in the body, for clients that don't
        // present the URL property prominently.
        $parts[] = 'Open in Madori: ' . $this->deepLink($task, $baseUrl);

        return implode("\n\n", $parts);
    }

    private function deepLink(Task $task, string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/tasks?task=' . $task->getId();
    }

    /**
     * Due-dated tasks the user owns or is assigned to.
     *
     * @return list<Task>
     */
    private function fetchTasks(User $user): array
    {
        // MEMBER OF (rather than a JOIN + DISTINCT) avoids SELECT DISTINCT over
        // the task's json columns, which Postgres can't compare for equality.
        /** @var list<Task> $tasks */
        $tasks = $this->em->getRepository(Task::class)->createQueryBuilder('t')
            ->andWhere('t.dueDate IS NOT NULL')
            ->andWhere('t.owner = :user OR :user MEMBER OF t.assignees')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        return $tasks;
    }

    private function resolveTimezone(User $user): \DateTimeZone
    {
        $tz = $user->getPreferences()['timezone'] ?? 'UTC';
        if (!is_string($tz)) {
            return new \DateTimeZone('UTC');
        }
        try {
            return new \DateTimeZone($tz);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    private function calendarName(User $user): string
    {
        $name = trim($user->getGivenName() . ' ' . $user->getFamilyName());

        return '' !== $name ? $name . ' — Madori tasks' : 'Madori tasks';
    }

    /** Host portion of the app origin, for globally-unique UIDs. */
    private function domainFor(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);

        return is_string($host) && '' !== $host ? $host : 'madori';
    }
}
