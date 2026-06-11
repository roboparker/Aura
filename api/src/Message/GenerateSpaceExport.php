<?php

namespace App\Message;

/**
 * Queue message: build the zip archive for one requested space export and
 * email the requester a download link. Dispatched by
 * App\Controller\SpaceExportController on `POST /spaces/{id}/export`,
 * handled by App\MessageHandler\GenerateSpaceExportHandler on the worker.
 *
 * Carries only the SpaceExport row id — the download token is minted at
 * completion time so its plaintext never sits in the messenger_messages
 * table.
 */
final class GenerateSpaceExport
{
    public function __construct(
        public readonly string $exportId,
    ) {
    }
}
