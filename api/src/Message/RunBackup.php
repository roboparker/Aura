<?php

namespace App\Message;

/**
 * Scheduler message: run one backup pass (database dump + media archive +
 * retention prune — see App\Service\BackupRunner). Attached to the default
 * schedule nightly by App\Scheduler\MainScheduleProvider; carries no
 * payload because the runner derives everything from configuration.
 */
final class RunBackup
{
}
