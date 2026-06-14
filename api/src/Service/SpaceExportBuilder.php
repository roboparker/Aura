<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Discussion;
use App\Entity\MediaObject;
use App\Entity\Page;
use App\Entity\Project;
use App\Entity\SpaceExport;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use App\Service\Export\ExportArchiveWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Assembles the zip archive for one {@see SpaceExport} request.
 *
 * Layout inside the archive:
 *
 *   space.json        — space metadata + member roster
 *   projects.json     — projects with their custom field definitions
 *   tasks.json        — tasks of the space's projects (values, tags,
 *                       assignees, comments, attachment references)
 *   pages.json        — pages with their comment threads
 *   discussions.json  — discussions with their comment threads
 *   attachments/      — the actual files (space + task attachments),
 *                       named "<mediaId>-<originalName>" so the JSON
 *                       references resolve unambiguously
 *
 * The zip is written to a `.tmp` path and renamed only on success (same
 * convention as {@see BackupRunner}), so a crashed build never leaves a
 * truncated file that looks like a finished export. Attachment bytes are
 * read through the flysystem media storage, not the filesystem directly,
 * so a later local→S3 swap doesn't touch this class.
 */
final class SpaceExportBuilder
{
    public function __construct(
        private EntityManagerInterface $em,
        private ExportArchiveWriter $archive,
        #[Autowire('%app.space_export_dir%')]
        private string $exportDir,
    ) {
    }

    /**
     * Builds the archive and returns its absolute path.
     */
    public function build(SpaceExport $export): string
    {
        if (!is_dir($this->exportDir) && !mkdir($this->exportDir, 0750, true) && !is_dir($this->exportDir)) {
            throw new \RuntimeException(sprintf('Export directory "%s" could not be created.', $this->exportDir));
        }

        $space = $export->getSpace();
        $file = sprintf('%s/space-export-%s.zip', $this->exportDir, (string) $export->getId());
        $tmp = $file . '.tmp';

        // Attachment bytes are streamed to temp files in this directory and
        // referenced by ZipArchive::addFile(), which reads them lazily at
        // close() — so the worker never holds a whole attachment in memory.
        // The files must outlive the add calls, hence a directory we clean
        // up only after close() (or on the error path). A crashed build's
        // leftovers are swept by SpaceExportPruner alongside the .zip.tmp.
        $partsDir = sprintf('%s/space-export-%s.parts', $this->exportDir, (string) $export->getId());
        if (!is_dir($partsDir) && !mkdir($partsDir, 0750, true) && !is_dir($partsDir)) {
            throw new \RuntimeException(sprintf('Export staging directory "%s" could not be created.', $partsDir));
        }

        $projects = $this->em->getRepository(Project::class)
            ->findBy(['space' => $space], ['createdOn' => 'ASC']);
        $tasks = [] === $projects ? [] : $this->em->getRepository(Task::class)
            ->findBy(['project' => $projects], ['createdOn' => 'ASC']);
        $pages = $this->em->getRepository(Page::class)
            ->findBy(['space' => $space], ['createdAt' => 'ASC']);
        $discussions = $this->em->getRepository(Discussion::class)
            ->findBy(['space' => $space], ['createdAt' => 'ASC']);

        // Every attachment the export references, deduped by media id:
        // the space's shared file pool plus each task's attachments.
        /** @var array<string, MediaObject> $media */
        $media = [];
        foreach ($space->getAttachments() as $attachment) {
            $media[(string) $attachment->getId()] = $attachment;
        }
        foreach ($tasks as $task) {
            foreach ($task->getAttachments() as $attachment) {
                $media[(string) $attachment->getId()] = $attachment;
            }
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException(sprintf('Could not open "%s" for writing.', $tmp));
        }

        try {
            $taskComments = $this->commentsFor($tasks, 'task');
            $pageComments = $this->commentsFor($pages, 'page');
            $discussionComments = $this->commentsFor($discussions, 'discussion');

            $this->archive->addJson($zip, 'space.json', $this->spaceData($export));
            $this->archive->addJson($zip, 'projects.json', array_map($this->projectData(...), $projects));
            $this->archive->addJson($zip, 'tasks.json', array_map(
                fn (Task $t): array => $this->taskData($t, $taskComments),
                $tasks,
            ));
            $this->archive->addJson($zip, 'pages.json', array_map(
                fn (Page $p): array => $this->pageData($p, $pageComments),
                $pages,
            ));
            $this->archive->addJson($zip, 'discussions.json', array_map(
                fn (Discussion $d): array => $this->discussionData($d, $discussionComments),
                $discussions,
            ));

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

    /**
     * @return array<string, mixed>
     */
    private function spaceData(SpaceExport $export): array
    {
        $space = $export->getSpace();

        return [
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'requestedBy' => $this->userRef($export->getRequestedBy()),
            'space' => [
                'id' => (string) $space->getId(),
                'name' => $space->getName(),
                'description' => $space->getDescription(),
                'isPersonal' => $space->getIsPersonal(),
                'visibility' => $space->getVisibility(),
                'createdAt' => $space->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'createdBy' => $this->userRef($space->getCreatedBy()),
            ],
            'members' => array_values(array_map($this->userRef(...), $space->getEffectiveUsers())),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectData(Project $project): array
    {
        $definitions = $this->em->getRepository(CustomFieldDefinition::class)
            ->findBy(['project' => $project], ['position' => 'ASC']);

        return [
            'id' => (string) $project->getId(),
            'title' => $project->getTitle(),
            'description' => $project->getDescription(),
            'createdOn' => $project->getCreatedOn()->format(\DateTimeInterface::ATOM),
            'createdBy' => $this->userRef($project->getOwner()),
            'customFieldDefinitions' => array_map(static fn (CustomFieldDefinition $d): array => [
                'id' => (string) $d->getId(),
                'name' => $d->getName(),
                'kind' => $d->getKind(),
                'subtype' => $d->getSubtype(),
                'config' => $d->getConfig(),
                'footer' => $d->getFooter(),
                'position' => $d->getPosition(),
            ], $definitions),
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $commentsByParent
     *
     * @return array<string, mixed>
     */
    private function taskData(Task $task, array $commentsByParent): array
    {
        return [
            'id' => (string) $task->getId(),
            'projectId' => null !== $task->getProject() ? (string) $task->getProject()->getId() : null,
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'dueDate' => $task->getDueDate()?->format(\DateTimeInterface::ATOM),
            'completedOn' => $task->getCompletedOn()?->format(\DateTimeInterface::ATOM),
            'createdOn' => $task->getCreatedOn()->format(\DateTimeInterface::ATOM),
            'recurrenceRule' => $task->getRecurrenceRule(),
            'owner' => $this->userRef($task->getOwner()),
            'assignees' => array_map($this->userRef(...), $task->getAssignees()->toArray()),
            'tags' => array_map(static fn (Tag $t): string => $t->getTitle(), $task->getTags()->toArray()),
            'customFieldValues' => array_map(static fn (CustomFieldValue $v): array => [
                'definitionId' => null !== $v->getDefinition() ? (string) $v->getDefinition()->getId() : null,
                'name' => $v->getDefinition()?->getName(),
                'value' => $v->getValue(),
            ], $task->getCustomFieldValues()->toArray()),
            'attachments' => array_map(fn (MediaObject $m): array => [
                'id' => (string) $m->getId(),
                'file' => $this->archive->attachmentFileName($m),
                'originalName' => $m->getOriginalName(),
            ], $task->getAttachments()->toArray()),
            'comments' => $commentsByParent[(string) $task->getId()] ?? [],
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $commentsByParent
     *
     * @return array<string, mixed>
     */
    private function pageData(Page $page, array $commentsByParent): array
    {
        return [
            'id' => (string) $page->getId(),
            'parentId' => null !== $page->getParent() ? (string) $page->getParent()->getId() : null,
            'title' => $page->getTitle(),
            'body' => $page->getBody(),
            'position' => $page->getPosition(),
            'createdAt' => $page->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'createdBy' => $this->userRef($page->getCreatedBy()),
            'comments' => $commentsByParent[(string) $page->getId()] ?? [],
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $commentsByParent
     *
     * @return array<string, mixed>
     */
    private function discussionData(Discussion $discussion, array $commentsByParent): array
    {
        return [
            'id' => (string) $discussion->getId(),
            'title' => $discussion->getTitle(),
            'body' => $discussion->getBody(),
            'category' => $discussion->getCategory(),
            'isPinned' => $discussion->getIsPinned(),
            'isLocked' => $discussion->getIsLocked(),
            'createdAt' => $discussion->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'author' => $this->userRef($discussion->getAuthor()),
            'comments' => $commentsByParent[(string) $discussion->getId()] ?? [],
        ];
    }

    /**
     * One IN-query comment lookup per parent kind, grouped by parent id.
     *
     * @param array<int, Task>|array<int, Page>|array<int, Discussion> $parents
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function commentsFor(array $parents, string $field): array
    {
        if ([] === $parents) {
            return [];
        }

        $comments = $this->em->getRepository(Comment::class)
            ->findBy([$field => $parents], ['createdAt' => 'ASC']);

        $grouped = [];
        foreach ($comments as $comment) {
            $parent = $comment->getCommentable();
            if (null === $parent) {
                continue;
            }
            $grouped[(string) $parent->getId()][] = [
                'id' => (string) $comment->getId(),
                'author' => $this->userRef($comment->getAuthor()),
                'body' => $comment->getBody(),
                'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $grouped;
    }

    /**
     * @return array<string, string|null>|null
     */
    private function userRef(?User $user): ?array
    {
        if (null === $user) {
            return null;
        }

        return [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'givenName' => $user->getGivenName(),
            'familyName' => $user->getFamilyName(),
            'nickname' => $user->getNickname(),
        ];
    }
}
