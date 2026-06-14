<?php

namespace App\Service;

use App\Validator\ValidReminders;

/**
 * Shared reminder logic for the expanded reminder model (#227 tasks
 * redesign). One place computes a reminder's canonical key (dedup +
 * idempotency) and its fire times, so the validator
 * ({@see \App\Validator\ValidRemindersValidator}) and the dispatcher
 * ({@see TaskReminderDispatcher}) can never drift apart.
 *
 * Reminder entries are objects:
 *  - relative: {type:"relative", value:int>=0, unit:"minutes"|"hours"|"days", repeat:bool}
 *  - absolute: {type:"absolute", at:ISO-8601, repeat:bool}
 */
final class ReminderScheduler
{
    /**
     * True when the entry is a well-formed relative or absolute reminder.
     *
     * @param array<array-key, mixed> $reminder
     */
    public function isValidShape(array $reminder): bool
    {
        $type = $reminder['type'] ?? null;
        // `repeat` is optional but, when present, must be a bool.
        if (array_key_exists('repeat', $reminder) && !is_bool($reminder['repeat'])) {
            return false;
        }

        if ('relative' === $type) {
            $value = $reminder['value'] ?? null;
            $unit = $reminder['unit'] ?? null;
            return is_int($value)
                && $value >= 0
                && is_string($unit)
                && in_array($unit, ValidReminders::ALLOWED_UNITS, true);
        }

        if ('absolute' === $type) {
            $at = $reminder['at'] ?? null;
            return is_string($at) && null !== $this->parseAt($at);
        }

        return false;
    }

    /**
     * True when the reminder needs a due date to anchor to (relative only).
     *
     * @param array<array-key, mixed> $reminder
     */
    public function requiresDueDate(array $reminder): bool
    {
        return 'relative' === ($reminder['type'] ?? null);
    }

    /**
     * A stable identifier for the reminder, independent of any single fire.
     * Used to dedup the list and as the base of the per-fire notification key.
     *
     * @param array<array-key, mixed> $reminder
     */
    public function canonicalKey(array $reminder): string
    {
        $type = $reminder['type'] ?? null;
        if ('relative' === $type) {
            $value = is_int($reminder['value'] ?? null) ? $reminder['value'] : 0;
            $unit = is_string($reminder['unit'] ?? null) ? $reminder['unit'] : 'minutes';
            return sprintf('rel:%d:%s', $value, $unit);
        }
        if ('absolute' === $type) {
            $at = is_string($reminder['at'] ?? null) ? $reminder['at'] : '';
            $parsed = $this->parseAt($at);
            return 'abs:' . ($parsed?->format(\DateTimeInterface::ATOM) ?? $at);
        }
        return 'unknown:' . md5(serialize($reminder));
    }

    /**
     * The fires for this reminder that are due at or before `$now`.
     *
     * For a one-shot reminder that's at most one entry (the anchor time). For
     * a "repeat daily until done" reminder it's the most recent daily fire
     * <= now (we don't backfill missed days — one catch-up is enough), keyed
     * with the fire date so each day is a distinct notification.
     *
     * @param array<array-key, mixed> $reminder
     *
     * @return list<array{key: string, at: \DateTimeImmutable}>
     */
    public function dueFires(
        array $reminder,
        ?\DateTimeImmutable $due,
        \DateTimeImmutable $now,
    ): array {
        $anchor = $this->anchorTime($reminder, $due);
        if (null === $anchor || $anchor > $now) {
            return [];
        }

        $baseKey = $this->canonicalKey($reminder);
        $repeat = ($reminder['repeat'] ?? false) === true;

        if (!$repeat) {
            return [['key' => $baseKey, 'at' => $anchor]];
        }

        // Latest daily occurrence at the anchor's clock time, <= now.
        $secondsSince = $now->getTimestamp() - $anchor->getTimestamp();
        $daysSince = intdiv($secondsSince, 86400);
        $latest = $anchor->modify(sprintf('+%d days', $daysSince));
        return [[
            'key' => $baseKey . ':' . $latest->format('Y-m-d'),
            'at' => $latest,
        ]];
    }

    /**
     * Human-readable description for emails / notification bodies.
     *
     * @param array<array-key, mixed> $reminder
     */
    public function describe(array $reminder): string
    {
        $type = $reminder['type'] ?? null;
        if ('relative' === $type) {
            $value = is_int($reminder['value'] ?? null) ? $reminder['value'] : 0;
            $unit = is_string($reminder['unit'] ?? null) ? $reminder['unit'] : 'minutes';
            if (0 === $value) {
                return 'when due';
            }
            $label = rtrim($unit, 's');
            return sprintf('%d %s%s before due', $value, $label, 1 === $value ? '' : 's');
        }
        if ('absolute' === $type) {
            $at = is_string($reminder['at'] ?? null) ? $reminder['at'] : '';
            $parsed = $this->parseAt($at);
            return null !== $parsed ? 'on ' . $parsed->format('M j, Y g:i a') : 'at a set time';
        }
        return 'reminder';
    }

    /**
     * The reminder's base fire time: due − offset for relative, the fixed
     * timestamp for absolute. Null when a relative reminder has no due date.
     *
     * @param array<array-key, mixed> $reminder
     */
    private function anchorTime(array $reminder, ?\DateTimeImmutable $due): ?\DateTimeImmutable
    {
        $type = $reminder['type'] ?? null;
        if ('relative' === $type) {
            if (null === $due) {
                return null;
            }
            $value = is_int($reminder['value'] ?? null) ? $reminder['value'] : 0;
            $unit = is_string($reminder['unit'] ?? null) ? $reminder['unit'] : 'minutes';
            return $due->modify(sprintf('-%d %s', $value, $unit));
        }
        if ('absolute' === $type) {
            $at = is_string($reminder['at'] ?? null) ? $reminder['at'] : '';
            return $this->parseAt($at);
        }
        return null;
    }

    private function parseAt(string $at): ?\DateTimeImmutable
    {
        if ('' === $at) {
            return null;
        }
        try {
            return new \DateTimeImmutable($at);
        } catch (\Exception) {
            return null;
        }
    }
}
