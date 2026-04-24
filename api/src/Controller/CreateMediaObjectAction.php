<?php

namespace App\Controller;

use App\Entity\MediaObject;
use App\Entity\User;
use App\Service\ImageUploadService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[AsController]
final class CreateMediaObjectAction
{
    public function __construct(
        private ImageUploadService $imageUploadService,
    ) {
    }

    public function __invoke(Request $request, #[CurrentUser] ?User $user): MediaObject
    {
        if (null === $user) {
            throw new AccessDeniedHttpException();
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('A "file" form field is required.');
        }

        return $this->imageUploadService->uploadAvatar($file, $user);
    }
}
