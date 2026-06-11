<?php

namespace App\Message;

/**
 * Scheduler message: delete space exports past their retention window —
 * the zip on disk and the space_export row (see
 * App\Service\SpaceExportPruner). Attached to the default schedule nightly
 * by App\Scheduler\MainScheduleProvider; carries no payload because the
 * pruner derives everything from configuration.
 */
final class PruneSpaceExports
{
}
