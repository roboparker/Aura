<?php

namespace App\Message;

/**
 * Nightly retention sweep for account exports. Carries no payload — the
 * pruner derives the window from config. Fired by the scheduler
 * ({@see \App\Scheduler\MainScheduleProvider}).
 */
final class PruneAccountExports
{
}
