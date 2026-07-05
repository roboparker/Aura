<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\CustomFieldDefinition;
use App\Entity\Board;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Shared shape for `/tasks/{id}/activity` and `/boards/{id}/activity`.
 * Returns the page of ActivityLog rows plus a small actor map keyed by
 * `username` (which we set to the user IRI in `LoggableUserListener`),
 * so the PWA can render avatars inline without N+1 user fetches.
 *
 * Schema:
 *   {
 *     "items": [ ActivityRow, … ],
 *     "totalItems": int,
 *     "page": int,
 *     "itemsPerPage": int,
 *     "actors": { "/users/{uuid}": { id, email, givenName, familyName, personalizedColor } }
 *   }
 *
 * Where `ActivityRow` shape mirrors a flattened Gedmo log entry —
 * action, loggedAt (ISO 8601), objectClass (short name: "Task" /
 * "Board"), objectId, version, actor (the username IRI), and `data`
 * (associative array of changed-field → new value).
 */
final class ActivityFeedSerializer
{
    /**
     * @param ActivityLog[] $rows
     * @return array<string, mixed>
     */
    public static function serialize(
        array $rows,
        int $totalItems,
        int $page,
        int $perPage,
        EntityManagerInterface $em,
    ): array {
        $actors = self::loadActors($rows, $em);

        $items = array_map(
            static fn (ActivityLog $log): array => [
                'id' => $log->getId(),
                'action' => $log->getAction(),
                'loggedAt' => $log->getLoggedAt()?->format(\DateTimeImmutable::ATOM),
                'objectClass' => self::shortClass($log->getObjectClass() ?? ''),
                'objectId' => $log->getObjectId(),
                'version' => $log->getVersion(),
                'actor' => $log->getUsername(),
                'data' => $log->getData() ?? [],
            ],
            $rows,
        );

        return [
            'items' => $items,
            'totalItems' => $totalItems,
            'page' => $page,
            'itemsPerPage' => $perPage,
            'actors' => $actors,
        ];
    }

    /**
     * @param ActivityLog[] $rows
     * @return array<string, array<string, mixed>>
     */
    private static function loadActors(array $rows, EntityManagerInterface $em): array
    {
        $iris = [];
        foreach ($rows as $row) {
            $username = $row->getUsername();
            if (null !== $username && '' !== $username) {
                $iris[$username] = true;
            }
        }
        if ([] === $iris) {
            return [];
        }

        // Usernames are stored as `/users/{uuid}` IRIs (see
        // LoggableUserListener). Strip the prefix and validate before
        // hitting the DB so we never accidentally `IN ()` with junk.
        $uuids = [];
        foreach (array_keys($iris) as $iri) {
            if (str_starts_with($iri, '/users/')) {
                $maybe = substr($iri, strlen('/users/'));
                if (Uuid::isValid($maybe)) {
                    $uuids[] = $maybe;
                }
            }
        }
        if ([] === $uuids) {
            return [];
        }

        $users = $em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $uuids)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($users as $user) {
            /** @var User $user */
            $iri = '/users/' . $user->getId();
            $map[$iri] = [
                '@id' => $iri,
                'id' => (string) $user->getId(),
                'email' => $user->getEmail(),
                'givenName' => $user->getGivenName(),
                'familyName' => $user->getFamilyName(),
                'personalizedColor' => $user->getPersonalizedColor(),
                'avatarUrls' => $user->getAvatarUrls(),
            ];
        }
        return $map;
    }

    private static function shortClass(string $fqcn): string
    {
        return match (true) {
            $fqcn === Task::class => 'Task',
            $fqcn === Board::class => 'Board',
            $fqcn === CustomFieldDefinition::class => 'CustomFieldDefinition',
            default => substr($fqcn, (int) strrpos($fqcn, '\\') + 1),
        };
    }
}
