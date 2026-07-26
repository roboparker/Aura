<?php

namespace App\Message;

/**
 * Scheduler message: email every admin last week's growth funnel
 * (see App\Service\GrowthDigestMailer). Attached to the default schedule
 * weekly by App\Scheduler\MainScheduleProvider; carries no payload because
 * the mailer defaults to the seven days ending today (UTC).
 */
final class SendGrowthDigest
{
}
