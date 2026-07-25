<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\TimeEntry;
use App\Entity\User;
use App\Mcp\McpBillingAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpInputHelper;
use App\Security\Permission\SpacePermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads tracked time. Defaults to the caller's own entries because "how much
 * have I logged this week?" is the overwhelmingly common question; pass
 * `mine: false` to see the whole space (subject to the same read permission).
 */
final class ListTimeEntriesTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpBillingAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'list_time_entries';
    }

    public function getDescription(): string
    {
        return 'List tracked time entries, newest first. Defaults to the user\'s own entries; filter by date range or project. Use to answer "what did I work on" and "how many hours" questions.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Only entries started on or after this ISO-8601 date/datetime.'],
                'to' => ['type' => 'string', 'description' => 'Only entries started on or before this ISO-8601 date/datetime.'],
                'projectId' => ['type' => 'string', 'description' => 'Only entries on this client project.'],
                'mine' => ['type' => 'boolean', 'description' => 'Restrict to the authenticated user\'s own entries. Defaults to true.'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum entries to return (1-100, default 50).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $spaces = $this->authz->readableSpaces($user, SpacePermission::TIME_ENTRIES);
        if ([] === $spaces) {
            return ['items' => [], 'totalDurationSeconds' => 0];
        }

        $qb = $this->em->getRepository(TimeEntry::class)
            ->createQueryBuilder('t')
            ->where('t.space IN (:spaces)')
            ->setParameter('spaces', $spaces)
            ->orderBy('t.startedAt', 'DESC');

        if (false !== ($arguments['mine'] ?? true)) {
            $qb->andWhere('t.user = :user')->setParameter('user', $user);
        }

        $from = $this->input->optionalDateTime($arguments, 'from');
        if (null !== $from) {
            $qb->andWhere('t.startedAt >= :from')->setParameter('from', $from);
        }

        $to = $this->input->optionalDateTime($arguments, 'to');
        if (null !== $to) {
            $qb->andWhere('t.startedAt <= :to')->setParameter('to', $to);
        }

        $projectId = $this->input->optionalUuid('projectId', $arguments['projectId'] ?? null);
        if (null !== $projectId) {
            $qb->andWhere('t.project = :project')->setParameter('project', $projectId);
        }

        $limit = $this->input->optionalInt($arguments, 'limit') ?? 50;
        $qb->setMaxResults(max(1, min(100, $limit)));

        /** @var list<TimeEntry> $entries */
        $entries = $qb->getQuery()->getResult();

        // Summed over the returned page only. Said plainly in the field name's
        // docs below so a model doesn't report it as an all-time total.
        $total = 0;
        foreach ($entries as $entry) {
            $total += $entry->getDurationSeconds() ?? 0;
        }

        return [
            'items' => array_map(
                fn (TimeEntry $entry) => $this->serializer->timeEntry($entry),
                $entries,
            ),
            'totalDurationSeconds' => $total,
            'totalIsForReturnedEntriesOnly' => true,
        ];
    }
}
