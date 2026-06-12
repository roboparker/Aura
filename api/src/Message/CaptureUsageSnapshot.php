<?php

namespace App\Message;

/**
 * Scheduler message: roll up yesterday's per-user usage into
 * {@see \App\Entity\UserUsageSnapshot} rows and prune spent counter buckets
 * (see App\Service\UsageSnapshotBuilder). Attached to the default schedule
 * nightly by App\Scheduler\MainScheduleProvider; carries no payload because
 * the builder defaults to yesterday (UTC).
 */
final class CaptureUsageSnapshot
{
}
