<?php

namespace App\Message;

/**
 * Scheduler message: hard-delete organizations, spaces, and accounts whose
 * deletion grace period has lapsed, and sweep spent restore tokens
 * (App\Deletion\PurgeRunner). Attached to the default schedule nightly by
 * App\Scheduler\MainScheduleProvider; carries no payload because the runner
 * derives everything from the stored `purge_after` stamps.
 */
final class PurgeDeletedRecords
{
}
