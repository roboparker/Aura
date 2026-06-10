<?php

namespace App\Message;

/**
 * Scheduler message: run one task-reminder dispatch pass (create in-app
 * notifications + emails + pushes for reminder offsets that have come
 * due). Attached to the default schedule every 5 minutes by
 * App\Scheduler\MainScheduleProvider; carries no payload because the
 * dispatcher re-derives everything from the database.
 */
final class DispatchTaskReminders
{
}
