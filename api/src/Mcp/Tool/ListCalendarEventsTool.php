<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Space;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Service\CalendarOccurrenceResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The "what's on my plate this week" tool. Unlike `list_tasks`, this expands
 * recurring series into their individual occurrences across the window, so a
 * weekly task shows up on each of its dates rather than once on its anchor.
 */
final class ListCalendarEventsTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private CalendarOccurrenceResolver $occurrences,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'list_calendar_events';
    }

    public function getDescription(): string
    {
        return 'List calendar occurrences for a space over a date window, expanding recurring tasks into one entry per occurrence. Use for "what is due / scheduled" questions over a range; use list_tasks for the underlying tasks themselves.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'spaceId' => ['type' => 'string', 'description' => 'UUID of the space (from list_spaces).'],
                'start' => ['type' => 'string', 'description' => 'First day of the window, Y-m-d.'],
                'end' => [
                    'type' => 'string',
                    'description' => sprintf(
                        'Last day of the window, Y-m-d. At most %d days after start.',
                        CalendarOccurrenceResolver::MAX_RANGE_DAYS,
                    ),
                ],
                'boardId' => ['type' => 'string', 'description' => 'Narrow to one board in that space.'],
            ],
            'required' => ['spaceId', 'start', 'end'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $spaceId = $this->input->requireUuid('spaceId', $this->input->requireString($arguments, 'spaceId'));

        $space = $this->em->getRepository(Space::class)->find($spaceId);
        if (null === $space || !$space->hasMember($user)) {
            throw McpException::notFound(sprintf('Space %s', $spaceId));
        }

        $start = $this->parseDay($this->input->requireString($arguments, 'start'), false);
        $end = $this->parseDay($this->input->requireString($arguments, 'end'), true);
        if ($end < $start) {
            throw McpException::invalidInput('`end` must be on or after `start`.');
        }
        // The projection materialises every occurrence in the window, so an
        // unbounded range is a denial-of-service on ourselves.
        if ($start->diff($end)->days > CalendarOccurrenceResolver::MAX_RANGE_DAYS) {
            throw McpException::invalidInput(sprintf(
                'Range is too large — request at most %d days at a time.',
                CalendarOccurrenceResolver::MAX_RANGE_DAYS,
            ));
        }

        $boardUuid = $this->input->optionalUuid('boardId', $arguments['boardId'] ?? null);
        $boardId = null === $boardUuid ? null : (string) $boardUuid;

        $items = [];
        foreach ($this->occurrences->occurrences($space, $user, $start, $end, $boardId) as $hit) {
            $items[] = $this->serializer->calendarOccurrence(
                $hit['task'],
                $hit['occurrence'],
                $hit['recurring'],
            );
        }

        return ['items' => $items];
    }

    private function parseDay(string $value, bool $endOfDay): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $day) {
            throw McpException::invalidInput(sprintf('"%s" is not a Y-m-d date.', $value));
        }

        return $endOfDay ? $day->setTime(23, 59, 59) : $day;
    }
}
