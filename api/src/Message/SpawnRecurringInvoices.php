<?php

namespace App\Message;

/**
 * Scheduler message: clone due recurring-template invoices into fresh drafts
 * (#445). Attached to the default schedule daily by
 * App\Scheduler\MainScheduleProvider; carries no payload.
 */
final class SpawnRecurringInvoices
{
}
