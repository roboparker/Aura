<?php

namespace App\Mcp\Tool;

use App\Entity\MediaObject;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DownloadFileTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire(service: 'media.storage')]
        private FilesystemOperator $storage,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'download_file';
    }

    public function getDescription(): string
    {
        return 'Fetch the bytes of an attachment as base64. The caller must be able to read at least one task or project that uses the file (mirrors GET /media-objects/{id}/download).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fileId' => ['type' => 'string', 'description' => 'UUID of the MediaObject.'],
            ],
            'required' => ['fileId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $fileId = $this->input->requireUuid('fileId', $arguments['fileId'] ?? null);
        $media = $this->em->getRepository(MediaObject::class)->find($fileId);
        if (null === $media || !$this->canAccess($media, $user)) {
            throw McpException::notFound(sprintf('File %s', $fileId));
        }

        $path = $media->getVariantPath('original') ?? array_values($media->getVariants())[0] ?? null;
        if (null === $path) {
            throw McpException::notFound('File contents');
        }

        try {
            $bytes = $this->storage->read($path);
        } catch (FilesystemException) {
            throw McpException::notFound('File contents');
        }

        return [
            'id' => (string) $fileId,
            'name' => $media->getOriginalName(),
            'mimeType' => $media->getMimeType(),
            'byteSize' => $media->getByteSize(),
            'content' => base64_encode($bytes),
        ];
    }

    /**
     * Mirrors {@see \App\Controller\MediaObjectDownloadController::canAccess()} —
     * caller is the uploader, OR at least one task/project they can read
     * attaches the media.
     */
    private function canAccess(MediaObject $media, User $user): bool
    {
        if ($media->getOwner()?->getId()?->equals($user->getId())) {
            return true;
        }

        $taskHit = (int) $this->em->getRepository(Task::class)
            ->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->leftJoin('t.project', 'p')
            ->leftJoin('p.members', 'pm')
            ->where(':media MEMBER OF t.attachments')
            ->andWhere('t.owner = :user OR pm = :user')
            ->setParameter('media', $media)
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();
        if ($taskHit > 0) {
            return true;
        }

        $projectHit = (int) $this->em->getRepository(Project::class)
            ->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->innerJoin('p.members', 'pm')
            ->where(':media MEMBER OF p.attachments')
            ->andWhere('pm = :user')
            ->setParameter('media', $media)
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();
        return $projectHit > 0;
    }
}
