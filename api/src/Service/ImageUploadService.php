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
 * Processes avatar uploads: validates the incoming file, produces a 256px
 * profile variant and a 64px thumbnail via center-crop, writes both as WebP
 * to the shared media storage, and returns a persisted MediaObject.
 *
 * Today only `uploadAvatar` exists; task/comment attachments will grow
 * sibling methods here using the same MediaObject + Flysystem plumbing.
 */
final class ImageUploadService
{
    private const MAX_AVATAR_BYTES = 5 * 1024 * 1024;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const AVATAR_PROFILE_SIZE = 256;
    private const AVATAR_THUMB_SIZE = 64;
    private const WEBP_QUALITY = 85;

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
        $media->setOriginalName($file->getClientOriginalName() ?: 'avatar');
        $media->setMimeType('image/webp');
        $media->setByteSize($file->getSize() ?: 0);

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
}
