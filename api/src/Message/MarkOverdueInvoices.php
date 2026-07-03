<?php

namespace App\Message;

/**
 * Scheduler message: flip sent invoices whose due date has passed to overdue
 * (#445). Attached to the default schedule daily by
 * App\Scheduler\MainScheduleProvider; carries no payload — the handler
 * re-derives everything from the database.
 */
final class MarkOverdueInvoices
{
}
