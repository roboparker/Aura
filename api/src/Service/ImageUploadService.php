<?php

namespace App\Service;

use App\Entity\MediaObject;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Processes media uploads. Avatars are validated, re-encoded as WebP, and
 * stored as paired profile/thumb variants. Generic attachments
 * ({@see uploadAttachment}) are stored as-is under a single `original`
 * variant — bytes are written verbatim because the variety of accepted
 * MIME types makes blanket re-encoding pointless.
 */
final class ImageUploadService
{
    private const MAX_AVATAR_BYTES = 5 * 1024 * 1024;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const AVATAR_PROFILE_SIZE = 256;
    private const AVATAR_THUMB_SIZE = 64;
    private const WEBP_QUALITY = 85;

    public const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;
    /**
     * Allowlist for task attachments. Intentionally narrow at v1 — common
     * collaboration files only. Extending the list later is a config
     * change rather than new wiring.
     */
    public const ALLOWED_ATTACHMENT_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
        'text/plain',
        'text/markdown',
        'text/csv',
        'application/zip',
        'application/json',
    ];

    public function __construct(
        #[Autowire(service: 'media.storage')]
        private FilesystemOperator $storage,
        private EntityManagerInterface $em,
    ) {
    }

    public function uploadAvatar(UploadedFile $file, User $owner): MediaObject
    {
        $this->assertValidImage($file);

        $manager = new ImageManager(new Driver());
        $image = $manager->decode($file->getPathname());

        $uuid = (string) Uuid::v7();
        $profilePath = sprintf('avatars/%s-profile.webp', $uuid);
        $thumbPath = sprintf('avatars/%s-thumb.webp', $uuid);

        $webp = new WebpEncoder(self::WEBP_QUALITY);

        $profile = (clone $image)->cover(self::AVATAR_PROFILE_SIZE, self::AVATAR_PROFILE_SIZE);
        $this->storage->write($profilePath, (string) $profile->encode($webp));

        $thumb = (clone $image)->cover(self::AVATAR_THUMB_SIZE, self::AVATAR_THUMB_SIZE);
        $this->storage->write($thumbPath, (string) $thumb->encode($webp));

        $media = new MediaObject();
        $media->setOwner($owner);
        $media->setKind(MediaObject::KIND_AVATAR);
        $media->setVariants(['profile' => $profilePath, 'thumb' => $thumbPath]);
        $clientName = $file->getClientOriginalName();
        $media->setOriginalName('' !== $clientName ? $clientName : 'avatar');
        $media->setMimeType('image/webp');
        $size = $file->getSize();
        $media->setByteSize(false !== $size ? $size : 0);

        $this->em->persist($media);
        $this->em->flush();

        return $media;
    }

    /**
     * Stores a generic attachment as-is. The file lives at
     * `attachments/{uuid}-{slug}.{ext}` so the original name survives
     * round-tripping (helpful when an admin needs to grep storage by
     * filename) without leaking unsafe characters into the path.
     */
    public function uploadAttachment(UploadedFile $file, User $owner): MediaObject
    {
        $this->assertValidAttachment($file);

        $uuid = (string) Uuid::v7();
        $clientName = $file->getClientOriginalName();
        $original = '' !== $clientName ? $clientName : 'file';
        $extension = pathinfo($original, PATHINFO_EXTENSION);
        $base = pathinfo($original, PATHINFO_FILENAME);
        // Strip path separators and other shell-unfriendly characters from
        // the name slug; the UUID prefix already guarantees uniqueness so
        // the slug is purely a human-readable hint.
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? 'file';
        $slug = trim($slug, '-');
        if ('' === $slug) {
            $slug = 'file';
        }
        $path = sprintf(
            'attachments/%s-%s%s',
            $uuid,
            substr($slug, 0, 80),
            $extension !== '' ? '.' . strtolower($extension) : '',
        );

        $contents = file_get_contents($file->getPathname());
        if (false === $contents) {
            throw new BadRequestHttpException('Could not read uploaded file.');
        }
        $this->storage->write($path, $contents);

        $media = new MediaObject();
        $media->setOwner($owner);
        $media->setKind(MediaObject::KIND_ATTACHMENT);
        $media->setVariants(['original' => $path]);
        $media->setOriginalName($original);
        $media->setMimeType($file->getMimeType() ?? 'application/octet-stream');
        $size = $file->getSize();
        $media->setByteSize(false !== $size ? $size : 0);

        $this->em->persist($media);
        $this->em->flush();

        return $media;
    }

    private function assertValidImage(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_AVATAR_BYTES) {
            throw new BadRequestHttpException('File is larger than 5 MB.');
        }

        $mime = $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new BadRequestHttpException(sprintf(
                'Unsupported image type "%s". Allowed: %s.',
                $mime ?? 'unknown',
                implode(', ', self::ALLOWED_MIMES),
            ));
        }
    }

    private function assertValidAttachment(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_ATTACHMENT_BYTES) {
            throw new BadRequestHttpException(sprintf(
                'File is larger than %d MB.',
                intdiv(self::MAX_ATTACHMENT_BYTES, 1024 * 1024),
            ));
        }

        $mime = $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_ATTACHMENT_MIMES, true)) {
            throw new BadRequestHttpException(sprintf(
                'Unsupported attachment type "%s".',
                $mime ?? 'unknown',
            ));
        }
    }
}
