<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\MediaObject;
use App\Entity\User;
use App\Service\ImageUploadService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Handles the multipart POST /media-objects upload.
 *
 * Implemented as a state processor (rather than a Symfony controller via
 * `controller:` on the Post operation) because in API Platform 4 a custom
 * controller bypasses the State framework and the kernel.view listener,
 * leaving the response unwrapped — Symfony then complains the controller
 * didn't return a Response. The processor stays inside API Platform's
 * pipeline, so the returned MediaObject is serialized normally.
 *
 * @implements ProcessorInterface<MediaObject|null, MediaObject>
 */
final class MediaObjectUploadProcessor implements ProcessorInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private Security $security,
        private ImageUploadService $imageUploadService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MediaObject
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            // Defensive — the operation security expression should already
            // have rejected this case before we reached the processor.
            throw new AccessDeniedHttpException();
        }

        $request = $this->requestStack->getCurrentRequest();
        $file = $request?->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('A "file" form field is required.');
        }

        return $this->imageUploadService->uploadAvatar($file, $user);
    }
}
