<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Discussion;
use App\Entity\Page;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Assembles a GDPR-style JSON export of the requesting user's own content.
 * Scoped strictly to rows the user authored/owns; co-appearing third parties
 * are referenced by id only (no third-party PII leaks through the export).
 */
final class UserDataExporter
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        return [
            'profile' => [
                'id' => (string) $user->getId(),
                'email' => $user->getEmail(),
                'givenName' => $user->getGivenName(),
                'familyName' => $user->getFamilyName(),
                'nickname' => $user->getNickname(),
                'roles' => $user->getRoles(),
            ],
            'preferences' => $user->getPreferences(),
            'tasks' => $this->tasks($user),
            'projects' => $this->projects($user),
            'pages' => $this->pages($user),
            'discussions' => $this->discussions($user),
            'comments' => $this->comments($user),
            'tags' => $this->tags($user),
            'apiTokens' => $this->apiTokens($user),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function tasks(User $user): array
    {
        $rows = $this->em->getRepository(Task::class)->findBy(['owner' => $user]);
        return array_map(static fn (Task $t): array => [
            'id' => (string) $t->getId(),
            'title' => $t->getTitle(),
            'description' => $t->getDescription(),
            'dueDate' => $t->getDueDate()?->format(\DateTimeInterface::ATOM),
            'completedOn' => $t->getCompletedOn()?->format(\DateTimeInterface::ATOM),
            'createdOn' => $t->getCreatedOn()->format(\DateTimeInterface::ATOM),
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function projects(User $user): array
    {
        $rows = $this->em->getRepository(Project::class)->findBy(['owner' => $user]);
        return array_map(static fn (Project $p): array => [
            'id' => (string) $p->getId(),
            'title' => $p->getTitle(),
            'description' => $p->getDescription(),
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function pages(User $user): array
    {
        $rows = $this->em->getRepository(Page::class)->findBy(['createdBy' => $user]);
        return array_map(static fn (Page $p): array => [
            'id' => (string) $p->getId(),
            'title' => $p->getTitle(),
            'body' => $p->getBody(),
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function discussions(User $user): array
    {
        $rows = $this->em->getRepository(Discussion::class)->findBy(['author' => $user]);
        return array_map(static fn (Discussion $d): array => [
            'id' => (string) $d->getId(),
            'title' => $d->getTitle(),
            'body' => $d->getBody(),
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function comments(User $user): array
    {
        $rows = $this->em->getRepository(Comment::class)->findBy(['author' => $user]);
        return array_map(static fn (Comment $c): array => [
            'id' => (string) $c->getId(),
            'body' => $c->getBody(),
            'createdAt' => $c->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function tags(User $user): array
    {
        $rows = $this->em->getRepository(Tag::class)->findBy(['owner' => $user]);
        return array_map(static fn (Tag $t): array => [
            'id' => (string) $t->getId(),
            'name' => $t->getTitle(),
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function apiTokens(User $user): array
    {
        $rows = $this->em->getRepository(\App\Entity\ApiToken::class)->findBy(['user' => $user]);
        return array_map(static fn (\App\Entity\ApiToken $t): array => [
            'id' => (string) $t->getId(),
            'name' => $t->getName(),
            'accessPolicy' => $t->getAccessPolicy(),
            'createdAt' => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $t->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ], $rows);
    }
}
