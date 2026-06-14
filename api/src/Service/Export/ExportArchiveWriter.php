<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\MediaObject;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shared zip-assembly primitives for the export builders
 * ({@see \App\Service\SpaceExportBuilder} / {@see \App\Service\AccountExportBuilder}).
 *
 * The builders compose this writer (inject + call) instead of mixing in a trait,
 * so the `media.storage` dependency lives here once rather than being an
 * implicit `$this->mediaStorage` contract each builder has to satisfy.
 */
final class ExportArchiveWriter
{
    public function __construct(
        #[Autowire(service: 'media.storage')]
        private readonly FilesystemOperator $mediaStorage,
    ) {
    }

    /**
     * Stable in-archive filename for an attachment: media id prefix keeps
     * names collision-free, the sanitized original name keeps them human.
     */
    public function attachmentFileName(MediaObject $media): string
    {
        $name = basename($media->getOriginalName());
        $sanitized = preg_replace('/[^\w.\- ]+/u', '_', $name);
        $safe = (null !== $sanitized && '' !== trim($sanitized)) ? trim($sanitized) : 'file';

        return sprintf('attachments/%s-%s', (string) $media->getId(), $safe);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public function addJson(\ZipArchive $zip, string $name, array $data): void
    {
        $json = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!$zip->addFromString($name, $json)) {
            throw new \RuntimeException(sprintf('Could not add "%s" to the export archive.', $name));
        }
    }

    public function addAttachment(\ZipArchive $zip, MediaObject $media, string $partsDir): void
    {
        $path = $media->getVariantPath('original') ?? array_values($media->getVariants())[0] ?? null;
        if (null === $path || !$this->mediaStorage->fileExists($path)) {
            // A missing file shouldn't sink the whole export — the JSON
            // still records that the attachment existed.
            return;
        }

        // Stream flysystem → a staged temp file, then hand the path to
        // ZipArchive::addFile() so the bytes are read from disk at close()
        // rather than buffered in memory. The media id is unique, so it's a
        // safe temp filename.
        $tempPath = $partsDir . '/' . (string) $media->getId();
        $dest = fopen($tempPath, 'wb');
        if (false === $dest) {
            throw new \RuntimeException(sprintf('Could not stage attachment "%s" for export.', (string) $media->getId()));
        }

        $source = $this->mediaStorage->readStream($path);
        $copied = stream_copy_to_stream($source, $dest);
        if (\is_resource($source)) {
            fclose($source);
        }
        fclose($dest);
        if (false === $copied) {
            // Couldn't read the bytes — skip rather than abort the export.
            unlink($tempPath);
            return;
        }

        if (!$zip->addFile($tempPath, $this->attachmentFileName($media))) {
            throw new \RuntimeException(sprintf('Could not add attachment "%s" to the export archive.', (string) $media->getId()));
        }
    }

    /**
     * Removes a staging directory and its (flat) contents. Best-effort — a
     * leftover that survives a hard kill is reaped by the matching export pruner.
     */
    public function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = glob($dir . '/*');
        if (false !== $entries) {
            foreach ($entries as $entry) {
                if (is_file($entry)) {
                    unlink($entry);
                }
            }
        }
        rmdir($dir);
    }
}
