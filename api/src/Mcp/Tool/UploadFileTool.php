<?php

namespace App\Mcp\Tool;

use App\Entity\Space;
use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Service\ImageUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class UploadFileTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private ImageUploadService $uploadService,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'upload_file';
    }

    public function getDescription(): string
    {
        return 'Attach a base64-encoded file to a task or space the user can edit. Mirrors POST /media-objects + PATCH attachment-link in one call. Allowed types match the PWA: images, PDF, plain/CSV text, Markdown, ZIP, JSON. 10 MB max.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entityType' => ['type' => 'string', 'enum' => ['task', 'space']],
                'entityId' => ['type' => 'string'],
                'fileName' => ['type' => 'string'],
                'content' => ['type' => 'string', 'description' => 'Base64-encoded file bytes.'],
            ],
            'required' => ['entityType', 'entityId', 'fileName', 'content'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $entityType = $this->input->requireString($arguments, 'entityType');
        if (!in_array($entityType, ['task', 'space'], true)) {
            throw McpException::invalidInput('entityType must be "task" or "space".');
        }
        $entityId = $this->input->requireUuid('entityId', $arguments['entityId'] ?? null);
        $fileName = $this->input->requireString($arguments, 'fileName');
        $contentB64 = $this->input->requireString($arguments, 'content');

        $owner = $this->resolveOwner($entityType, $entityId, $user);

        $bytes = base64_decode($contentB64, true);
        if (false === $bytes) {
            throw McpException::invalidInput('content must be valid base64.');
        }
        $tmpPath = tempnam(sys_get_temp_dir(), 'mcp_upload_');
        if (false === $tmpPath) {
            throw new McpException('Failed to allocate temp file.', 500);
        }
        file_put_contents($tmpPath, $bytes);

        $upload = new UploadedFile($tmpPath, $fileName, null, null, true);
        try {
            $media = $this->uploadService->uploadAttachment($upload, $user);
        } catch (BadRequestHttpException $e) {
            throw McpException::invalidInput($e->getMessage());
        }

        $owner->addAttachment($media);
        $this->em->flush();

        return $this->serializer->mediaObject($media);
    }

    private function resolveOwner(string $entityType, \Symfony\Component\Uid\Uuid $entityId, User $user): Task|Space
    {
        if ('task' === $entityType) {
            $task = $this->em->getRepository(Task::class)->find($entityId);
            if (null === $task || !$this->authz->canEditTask($task, $user)) {
                throw McpException::notFound(sprintf('Task %s', $entityId));
            }
            return $task;
        }
        $space = $this->em->getRepository(Space::class)->find($entityId);
        if (null === $space || !$space->hasMember($user)) {
            throw McpException::notFound(sprintf('Space %s', $entityId));
        }
        return $space;
    }
}
