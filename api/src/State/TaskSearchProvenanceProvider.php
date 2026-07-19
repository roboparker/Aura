<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Task;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Annotates task search results with *where* the query matched when the
 * hit came from a source the client can't see — a comment or a custom
 * field value. Title/description matches are left to the client (it
 * already has those fields); this only surfaces the hidden sources.
 *
 * Delegates to the stock
 * collection provider (so access scoping, filters, and pagination are
 * untouched), then — only when `?search=` is present — runs two batched
 * `ts_headline` queries over the page's task ids and attaches a
 * transient `searchMatches` list to each Task (serialized under
 * `task:read`, empty otherwise).
 *
 * @implements ProviderInterface<Task>
 */
final class TaskSearchProvenanceProvider implements ProviderInterface
{
    private const MAX_LENGTH = 200;

    /**
     * @param ProviderInterface<Task> $collectionProvider
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private ProviderInterface $collectionProvider,
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $data = $this->collectionProvider->provide($operation, $uriVariables, $context);

        $query = $this->searchQuery();
        if (null === $query || !is_iterable($data)) {
            return $data;
        }

        $tasks = [];
        foreach ($data as $task) {
            $tasks[] = $task;
        }
        $this->enrich($tasks, $query);

        return $data;
    }

    private function searchQuery(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }
        $raw = $request->query->get('search');
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return '' === $trimmed ? null : mb_substr($trimmed, 0, self::MAX_LENGTH);
    }

    /**
     * @param list<Task> $tasks
     */
    private function enrich(array $tasks, string $query): void
    {
        $ids = [];
        foreach ($tasks as $task) {
            $id = $task->getId();
            if (null !== $id) {
                $ids[(string) $id] = $task;
            }
        }
        if ([] === $ids) {
            return;
        }
        $taskIds = array_keys($ids);

        $matches = [];
        foreach ($this->loadCommentMatches($taskIds, $query) as $row) {
            $matches[$row['tid']][] = [
                'source' => 'comment',
                'label' => $row['author'],
                'snippet' => $this->cleanFragment($row['snippet']),
            ];
        }
        foreach ($this->loadCustomFieldMatches($taskIds, $query) as $row) {
            $matches[$row['tid']][] = [
                'source' => 'custom_field',
                'label' => $row['label'],
                'snippet' => $this->cleanFragment($row['snippet']),
            ];
        }

        foreach ($matches as $tid => $list) {
            $ids[$tid]->setSearchMatches($list);
        }
    }

    /**
     * One comment match per task (the earliest), with the author's
     * display name as the label.
     *
     * @param list<string> $taskIds
     *
     * @return list<array{tid: string, snippet: string, author: string}>
     */
    private function loadCommentMatches(array $taskIds, string $query): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT ON (c.task_id)
                c.task_id::text AS tid,
                ts_headline('english', c.body, websearch_to_tsquery('english', :q),
                    'MaxFragments=1,MaxWords=18,MinWords=4') AS snippet,
                COALESCE(NULLIF(TRIM(CONCAT(u.given_name, ' ', u.family_name)), ''), u.email) AS author
            FROM comment c
            JOIN "user" u ON u.id = c.author_id
            WHERE c.task_id::text IN (:ids)
                AND c.search_vector @@ websearch_to_tsquery('english', :q)
            ORDER BY c.task_id, c.created_at ASC
            SQL;

        /** @var list<array{tid: string, snippet: string, author: string}> $rows */
        $rows = $this->em->getConnection()->executeQuery(
            $sql,
            ['q' => $query, 'ids' => $taskIds],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return $rows;
    }

    /**
     * One custom-field match per task, with the field's name as the label.
     *
     * @param list<string> $taskIds
     *
     * @return list<array{tid: string, snippet: string, label: string}>
     */
    private function loadCustomFieldMatches(array $taskIds, string $query): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT ON (cfv.task_id)
                cfv.task_id::text AS tid,
                ts_headline('english', cfv.value_search, websearch_to_tsquery('english', :q),
                    'MaxFragments=1,MaxWords=18,MinWords=4') AS snippet,
                cfd.name AS label
            FROM custom_field_value cfv
            JOIN custom_field_definition cfd ON cfd.id = cfv.definition_id
            WHERE cfv.task_id::text IN (:ids)
                AND cfv.value_search IS NOT NULL
                AND cfv.search_vector @@ websearch_to_tsquery('english', :q)
            ORDER BY cfv.task_id, cfd.name ASC
            SQL;

        /** @var list<array{tid: string, snippet: string, label: string}> $rows */
        $rows = $this->em->getConnection()->executeQuery(
            $sql,
            ['q' => $query, 'ids' => $taskIds],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return $rows;
    }

    /**
     * Strip ts_headline's default `<b>` marks — the client re-highlights
     * the query terms over the clean fragment, so no HTML leaves the API.
     */
    private function cleanFragment(string $snippet): string
    {
        return trim((string) preg_replace('#</?b>#', '', $snippet));
    }
}
