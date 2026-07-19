<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Expense;
use App\Entity\MediaObject;
use App\Entity\Space;
use App\Entity\Task;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Authenticated download path for sensitive task attachments.
 *
 * The public `/media/...` files served by Caddy are unauthenticated — the
 * UUID-prefixed filename makes URL guessing impractical, but we don't want
 * task attachments (legal docs, financial PDFs, board files) to rely on
 * obscurity. This endpoint streams the bytes only after re-checking that the
 * caller is allowed to see at least one task that uses the MediaObject, or
 * that they uploaded it themselves.
 *
 * Avatar-kind media stays on the public `/media/` URL — those are publicly
 * visible inside the app already.
 *
 * 404 (not 403) is returned for unreachable IDs so the endpoint cannot be
 * used to enumerate which MediaObjects exist.
 */
class MediaObjectDownloadController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire(service: 'media.storage')]
        private FilesystemOperator $storage,
        private SpacePermissionResolver $permissions,
    ) {
    }

    #[Route(
        '/media-objects/{id}/download',
        name: 'media_object_download',
        methods: ['GET'],
    )]
    public function __invoke(string $id, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $media = $this->em->getRepository(MediaObject::class)->find($id);
        if (null === $media) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }
        if (!$this->canAccess($media, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        // Attachments expose a single "original" variant; avatars don't go
        // through this endpoint but defensively fall back to whatever
        // variant exists so a misconfigured caller still gets bytes back.
        $path = $media->getVariantPath('original') ?? array_values($media->getVariants())[0] ?? null;
        if (null === $path) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        try {
            $stream = $this->storage->readStream($path);
        } catch (FilesystemException) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $originalName = $media->getOriginalName();
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            '' !== $originalName ? $originalName : 'download',
        );

        return new StreamedResponse(
            function () use ($stream): void {
                if (\is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => '' !== $media->getMimeType() ? $media->getMimeType() : 'application/octet-stream',
                'Content-Length' => (string) $media->getByteSize(),
                'Content-Disposition' => $disposition,
                // Force a revalidation so updated/replaced bytes never get
                // served stale from a shared cache; the body itself is
                // private to the authenticated caller.
                'Cache-Control' => 'private, no-cache, must-revalidate',
            ],
        );
    }

    /**
     * The caller may download the MediaObject if they own it OR if at least
     * one task, space, or project they can read attaches it. Mirrors the
     * Task / Space / Project GET security expressions.
     */
    private function canAccess(MediaObject $media, User $user): bool
    {
        if (true === $media->getOwner()?->getId()?->equals($user->getId())) {
            return true;
        }
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->mediaIsAttachedToAnyTask($media)
                || $this->mediaIsAttachedToAnySpace($media)
                || $this->mediaIsAttachedToAnyProject($media)
                || $this->mediaIsAReceiptOnAnyExpense($media);
        }
        return $this->mediaIsAttachedToReadableTask($media, $user)
            || $this->mediaIsAttachedToReadableSpace($media, $user)
            || $this->mediaIsAttachedToReadableProject($media, $user)
            || $this->mediaIsAReceiptOnReadableExpense($media, $user);
    }

    private function mediaIsAttachedToAnyTask(MediaObject $media): bool
    {
        $count = (int) $this->em->getRepository(Task::class)
            ->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where(':media MEMBER OF t.attachments')
            ->setParameter('media', $media)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
    }

    private function mediaIsAttachedToReadableTask(MediaObject $media, User $user): bool
    {
        // A task is readable to a non-admin user when they own it OR
        // they're a member of its board's space (#185). EXISTS
        // subqueries cover both direct membership and group-inherited
        // membership; same shape as TaskOwnerExtension.
        $directSubquery = sprintf(
            'SELECT 1 FROM %s media_dl_direct WHERE media_dl_direct.space = p.space AND media_dl_direct.user = :user',
            \App\Entity\SpaceMembership::class,
        );
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s media_dl_group_obj JOIN media_dl_group_obj.memberships media_dl_group_member WHERE media_dl_group_obj.space = p.space AND media_dl_group_member.user = :user',
            \App\Entity\UserGroup::class,
        );
        $count = (int) $this->em->getRepository(Task::class)
            ->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->leftJoin('t.board', 'p')
            ->where(':media MEMBER OF t.attachments')
            ->andWhere(sprintf('(t.owner = :user OR EXISTS(%s) OR EXISTS(%s))', $directSubquery, $groupSubquery))
            ->setParameter('media', $media)
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
    }

    private function mediaIsAttachedToAnySpace(MediaObject $media): bool
    {
        $count = (int) $this->em->getRepository(Space::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where(':media MEMBER OF s.attachments')
            ->setParameter('media', $media)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
    }

    private function mediaIsAttachedToReadableSpace(MediaObject $media, User $user): bool
    {
        // Any member of the space (direct or via group) can read its
        // attachments — same rule as the space's read security
        // expression.
        $directSubquery = sprintf(
            'SELECT 1 FROM %s space_attach_direct WHERE space_attach_direct.space = s AND space_attach_direct.user = :user',
            \App\Entity\SpaceMembership::class,
        );
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s space_attach_group_obj JOIN space_attach_group_obj.memberships space_attach_group_member WHERE space_attach_group_obj.space = s AND space_attach_group_member.user = :user',
            \App\Entity\UserGroup::class,
        );
        $count = (int) $this->em->getRepository(Space::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where(':media MEMBER OF s.attachments')
            ->andWhere(sprintf('(EXISTS(%s) OR EXISTS(%s))', $directSubquery, $groupSubquery))
            ->setParameter('media', $media)
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
    }

    private function mediaIsAttachedToAnyProject(MediaObject $media): bool
    {
        $count = (int) $this->em->getRepository(Project::class)
            ->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where(':media MEMBER OF e.attachments')
            ->setParameter('media', $media)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
    }

    private function mediaIsAReceiptOnAnyExpense(MediaObject $media): bool
    {
        $count = (int) $this->em->getRepository(Expense::class)
            ->createQueryBuilder('x')
            ->select('COUNT(x.id)')
            ->where('x.receipt = :media')
            ->setParameter('media', $media)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
    }

    /**
     * Expense receipts (#650) are readable by the expense's own spender, a
     * space admin, or a member holding an explicit `time_entries.read` grant
     * — the same bar as the Expense GET security expression. A media backs at
     * most a handful of expenses, so the per-row check is cheap.
     */
    private function mediaIsAReceiptOnReadableExpense(MediaObject $media, User $user): bool
    {
        $expenses = $this->em->getRepository(Expense::class)
            ->createQueryBuilder('x')
            ->where('x.receipt = :media')
            ->setParameter('media', $media)
            ->getQuery()
            ->getResult();

        foreach ($expenses as $expense) {
            if (true === $expense->getUser()?->getId()?->equals($user->getId())) {
                return true;
            }
            $space = $expense->getSpace();
            if (null === $space || !$space->hasMember($user)) {
                continue;
            }
            if (
                $space->isAdmin($user)
                || $this->permissions->can($user, $space, SpacePermission::TIME_ENTRIES, SpacePermission::READ)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Project attachments are contract-sensitive: readable only by a space
     * admin or a member holding an explicit `invoices.read` grant — the same
     * bar as the Project GET security expression. A media is attached to at
     * most a handful of projects, so the per-row permission check is cheap.
     */
    private function mediaIsAttachedToReadableProject(MediaObject $media, User $user): bool
    {
        $projects = $this->em->getRepository(Project::class)
            ->createQueryBuilder('e')
            ->where(':media MEMBER OF e.attachments')
            ->setParameter('media', $media)
            ->getQuery()
            ->getResult();

        foreach ($projects as $project) {
            $space = $project->getSpace();
            if (null === $space) {
                continue;
            }
            if (
                $space->isAdmin($user)
                || $this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, SpacePermission::READ)
            ) {
                return true;
            }
        }

        return false;
    }
}
