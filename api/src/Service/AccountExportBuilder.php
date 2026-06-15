<?php

namespace App\Service;

use App\Entity\AccountExport;
use App\Entity\MediaObject;
use App\Service\Export\ExportArchiveWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Assembles the zip archive for one {@see AccountExport} request.
 *
 * Layout inside the archive:
 *
 *   account.json      — the requester's own data (profile, preferences,
 *                       tasks, projects, pages, discussions, comments, tags,
 *                       API tokens) plus an `attachments` manifest
 *   attachments/      — the actual files the user uploaded (avatar +
 *                       attachments), named "<mediaId>-<originalName>" so the
 *                       manifest references resolve unambiguously
 *
 * The JSON payload reuses {@see UserDataExporter} (the same own-data scoping
 * that backed the old synchronous export), so co-appearing third parties are
 * referenced by id only — no third-party PII leaks through the archive.
 *
 * The zip is written to a `.tmp` path and renamed only on success (same
 * convention as {@see SpaceExportBuilder} / {@see BackupRunner}). Attachment
 * bytes are streamed through flysystem media storage to staged temp files,
 * so the worker never holds a whole attachment in memory and a later
 * local→S3 swap doesn't touch this class.
 */
final class AccountExportBuilder
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserDataExporter $exporter,
        private ExportArchiveWriter $archive,
        #[Autowire('%app.account_export_dir%')]
        private string $exportDir,
    ) {
    }

    /**
     * Builds the archive and returns its absolute path.
     */
    public function build(AccountExport $export): string
    {
        if (!is_dir($this->exportDir) && !mkdir($this->exportDir, 0750, true) && !is_dir($this->exportDir)) {
            throw new \RuntimeException(sprintf('Export directory "%s" could not be created.', $this->exportDir));
        }

        $user = $export->getRequestedBy();
        $file = sprintf('%s/account-export-%s.zip', $this->exportDir, (string) $export->getId());
        $tmp = $file . '.tmp';

        // Attachment bytes are streamed to temp files in this directory and
        // referenced by ZipArchive::addFile(), which reads them lazily at
        // close() — so the worker never holds a whole attachment in memory.
        $partsDir = sprintf('%s/account-export-%s.parts', $this->exportDir, (string) $export->getId());
        if (!is_dir($partsDir) && !mkdir($partsDir, 0750, true) && !is_dir($partsDir)) {
            throw new \RuntimeException(sprintf('Export staging directory "%s" could not be created.', $partsDir));
        }

        // Every media object the user uploaded — avatar + attachments — keyed
        // by id so the manifest and the files agree.
        /** @var array<string, MediaObject> $media */
        $media = [];
        foreach ($this->em->getRepository(MediaObject::class)->findBy(['owner' => $user]) as $mediaObject) {
            $media[(string) $mediaObject->getId()] = $mediaObject;
        }

        $account = $this->exporter->export($user);
        $account['exportedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $account['attachments'] = array_values(array_map(fn (MediaObject $m): array => [
            'id' => (string) $m->getId(),
            'kind' => $m->getKind(),
            'originalName' => $m->getOriginalName(),
            'mimeType' => $m->getMimeType(),
            'byteSize' => $m->getByteSize(),
            'file' => $this->archive->attachmentFileName($m),
        ], $media));

        $zip = new \ZipArchive();
        if (true !== $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException(sprintf('Could not open "%s" for writing.', $tmp));
        }

        try {
            $this->archive->addJson($zip, 'account.json', $account);

            foreach ($media as $mediaObject) {
                $this->archive->addAttachment($zip, $mediaObject, $partsDir);
            }
        } catch (\Throwable $e) {
            $zip->close();
            if (is_file($tmp)) {
                unlink($tmp);
            }
            $this->archive->removeDir($partsDir);
            throw $e;
        }

        // close() flushes the archive, reading every addFile() source — so
        // the staging directory must survive until here, then it's safe to
        // remove regardless of success.
        $closed = $zip->close();
        $this->archive->removeDir($partsDir);
        if (!$closed) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            throw new \RuntimeException(sprintf('Could not finalize the export archive "%s".', $tmp));
        }
        if (!rename($tmp, $file)) {
            throw new \RuntimeException(sprintf('Could not move "%s" into place as "%s".', $tmp, $file));
        }

        return $file;
    }
}
