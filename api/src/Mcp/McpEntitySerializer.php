<?php

namespace App\Mcp;

use App\Entity\Client;
use App\Entity\Comment;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Estimate;
use App\Entity\Expense;
use App\Entity\Invoice;
use App\Entity\MediaObject;
use App\Entity\Notification;
use App\Entity\Page;
use App\Entity\Board;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\TaskRelationshipRepository;

/**
 * Plain-array serialization for the MCP tool responses. We intentionally
 * don't reuse the API Platform JSON-LD serializer here — its `@id`/`@type`
 * envelope adds noise the model has to learn around, and tool calls
 * don't need hypermedia. The shapes below are stable, hand-written
 * mirrors of the API entities, with relations flattened to ID + label
 * so the AI can chain calls (`get_task` → `assignee.id` → `assign_task`).
 */
final class McpEntitySerializer
{
    public function __construct(private TaskRelationshipRepository $relationships)
    {
    }

    /**
     * Serialize a whole page of tasks, batching the subtask rollup into one
     * query. Collection tools must use this rather than mapping over
     * {@see task()} themselves — that's what keeps `list_tasks`,
     * `get_my_tasks` and `search_tasks` from becoming an N+1.
     *
     * @param list<Task> $tasks
     *
     * @return list<array<string, mixed>>
     */
    public function tasks(array $tasks): array
    {
        $ids = [];
        foreach ($tasks as $task) {
            $ids[] = (string) $task->getId();
        }
        $progress = $this->relationships->subtaskProgressFor($ids);

        return array_map(
            fn (Task $task) => $this->task($task, $progress[(string) $task->getId()] ?? null),
            $tasks,
        );
    }

    /**
     * @param array{total: int, completed: int}|null $subtaskProgress rollup for
     *        this task, when the caller has one. Passed in rather than queried
     *        here because `task()` also serializes whole pages — looking it up
     *        per task would turn those into an N+1. Use {@see tasks()} for
     *        collections; it batches.
     *
     * @return array<string, mixed>
     */
    public function task(Task $task, ?array $subtaskProgress = null): array
    {
        return [
            'id' => (string) $task->getId(),
            'title' => $task->getTitle(),
            // Always present so a model needn't special-case its absence;
            // total 0 means "no subtasks".
            'subtasks' => $subtaskProgress ?? ['total' => 0, 'completed' => 0],
            'description' => $task->getDescription(),
            'status' => null === $task->getCompletedOn() ? 'open' : 'completed',
            'completedOn' => $task->getCompletedOn()?->format(\DateTimeInterface::ATOM),
            'dueDate' => $task->getDueDate()?->format(\DateTimeInterface::ATOM),
            'createdOn' => $task->getCreatedOn()->format(\DateTimeInterface::ATOM),
            'recurrenceRule' => $task->getRecurrenceRule(),
            'reminders' => $task->getReminders(),
            'owner' => $this->userSummary($task->getOwner()),
            'board' => null === $task->getBoard() ? null : $this->boardSummary($task->getBoard()),
            'assignees' => array_map(
                fn (User $u) => $this->userSummary($u),
                $task->getAssignees()->toArray(),
            ),
            'tags' => array_map(
                fn (Tag $t) => ['id' => (string) $t->getId(), 'name' => $t->getTitle()],
                $task->getTags()->toArray(),
            ),
            'attachments' => array_map(
                fn (MediaObject $m) => $this->mediaObject($m),
                $task->getAttachments()->toArray(),
            ),
            'customFieldValues' => array_map(
                fn (CustomFieldValue $v) => [
                    'definitionId' => null === $v->getDefinition() ? null : (string) $v->getDefinition()->getId(),
                    'globalDefinitionId' => null === $v->getGlobalDefinition() ? null : (string) $v->getGlobalDefinition()->getId(),
                    'name' => $v->getEffectiveDefinition()?->getName(),
                    'value' => $v->getValue(),
                ],
                $task->getCustomFieldValues()->toArray(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function board(Board $board, ?int $taskCount = null, ?int $openTaskCount = null): array
    {
        // Members are derived from the parent space (direct + group)
        // since #185 — `Board::getEffectiveMembers()` returns a
        // dedupe-keyed map of every user with access to the board.
        $members = $board->getEffectiveMembers();
        return [
            'id' => (string) $board->getId(),
            'title' => $board->getTitle(),
            'description' => $board->getDescription(),
            'createdOn' => $board->getCreatedOn()->format(\DateTimeInterface::ATOM),
            'owner' => $this->userSummary($board->getOwner()),
            'memberCount' => count($members),
            'members' => array_map(
                fn (User $u) => $this->userSummary($u),
                array_values($members),
            ),
            'taskCount' => $taskCount,
            'openTaskCount' => $openTaskCount,
        ];
    }

    /**
     * Space summary. `role` is the viewer's relationship to the space —
     * `admin` when they hold the admin membership, otherwise `member` —
     * so the model knows whether it can manage members / delete content.
     *
     * @return array<string, mixed>
     */
    public function space(Space $space, User $viewer): array
    {
        return [
            'id' => (string) $space->getId(),
            'name' => $space->getName(),
            'description' => $space->getDescription(),
            'isPersonal' => $space->getIsPersonal(),
            'visibility' => $space->getVisibility(),
            'role' => $space->isAdmin($viewer) ? Space::ROLE_ADMIN : Space::ROLE_MEMBER,
            'boardCount' => $space->getBoardsCount(),
            'pageCount' => $space->getPagesCount(),
            'createdOn' => $space->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function page(Page $page): array
    {
        return [
            'id' => (string) $page->getId(),
            'title' => $page->getTitle(),
            'body' => $page->getBody(),
            'spaceId' => null === $page->getSpace() ? null : (string) $page->getSpace()->getId(),
            'parentId' => null === $page->getParent() ? null : (string) $page->getParent()->getId(),
            'position' => $page->getPosition(),
            'createdBy' => $this->userSummary($page->getCreatedBy()),
            'createdOn' => $page->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedOn' => $page->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tag(Tag $tag): array
    {
        return [
            'id' => (string) $tag->getId(),
            'title' => $tag->getTitle(),
            'color' => $tag->getColor(),
            'description' => $tag->getDescription(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function comment(Comment $comment): array
    {
        return [
            'id' => (string) $comment->getId(),
            'commentableType' => $comment->getCommentableType(),
            'taskId' => null === $comment->getTask() ? null : (string) $comment->getTask()->getId(),
            'pageId' => null === $comment->getPage() ? null : (string) $comment->getPage()->getId(),
            'feedbackId' => null === $comment->getFeedback() ? null : (string) $comment->getFeedback()->getId(),
            'body' => $comment->getBody(),
            'author' => $this->userSummary($comment->getAuthor()),
            'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $comment->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mediaObject(MediaObject $media): array
    {
        return [
            'id' => (string) $media->getId(),
            'name' => $media->getOriginalName(),
            'mimeType' => $media->getMimeType(),
            'byteSize' => $media->getByteSize(),
            'kind' => $media->getKind(),
            'createdOn' => $media->getCreatedOn()->format(\DateTimeInterface::ATOM),
            'downloadUrl' => $media->getDownloadUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customFieldDefinition(CustomFieldDefinition $field): array
    {
        return [
            'id' => (string) $field->getId(),
            'name' => $field->getName(),
            'kind' => $field->getKind(),
            'subtype' => $field->getSubtype(),
            'config' => $field->getConfig(),
            'footer' => $field->getFooter(),
            'nullable' => $field->isNullable(),
            'position' => $field->getPosition(),
            'visibility' => $field->getVisibility(),
            'spaceId' => null === $field->getSpace() ? null : (string) $field->getSpace()->getId(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userSummary(?User $user): ?array
    {
        if (null === $user) {
            return null;
        }
        return [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'name' => trim($user->getGivenName() . ' ' . $user->getFamilyName()),
        ];
    }

    /**
     * Compact board label embedded inside Task responses. The full
     * member list is omitted to keep the payload tight.
     *
     * @return array<string, mixed>
     */
    public function boardSummary(Board $board): array
    {
        return [
            'id' => (string) $board->getId(),
            'title' => $board->getTitle(),
        ];
    }

    /**
     * Money is emitted as minor units plus an explicit currency, never a
     * pre-formatted string — a model that has to parse "$1,234.50" back into a
     * number will eventually parse it wrong.
     *
     * @return array<string, mixed>
     */
    public function timeEntry(TimeEntry $entry): array
    {
        $endedAt = $entry->getEndedAt();

        return [
            'id' => (string) $entry->getId(),
            'description' => $entry->getDescription(),
            'startedAt' => $entry->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'endedAt' => $endedAt?->format(\DateTimeInterface::ATOM),
            // The one field a model actually reasons about: is this the timer
            // that's still ticking?
            'running' => null === $endedAt,
            'durationSeconds' => $entry->getDurationSeconds(),
            'billable' => $entry->isBillable(),
            'rateAmount' => $entry->getRateAmount(),
            'rateCurrency' => $entry->getRateCurrency(),
            // Non-null means the entry is on an invoice and is now frozen.
            'billedAt' => $entry->getBilledAt()?->format(\DateTimeInterface::ATOM),
            'project' => $this->projectSummary($entry->getProject()),
            'category' => null === $entry->getCategory() ? null : [
                'id' => (string) $entry->getCategory()->getId(),
                'name' => $entry->getCategory()->getName(),
            ],
            'user' => null === $entry->getUser() ? null : $this->userSummary($entry->getUser()),
            'spaceId' => (string) $entry->getSpace()?->getId(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function projectSummary(?Project $project): ?array
    {
        if (null === $project) {
            return null;
        }

        return [
            'id' => (string) $project->getId(),
            'name' => $project->getName(),
            'clientName' => $project->getClient()?->getName(),
        ];
    }

    /**
     * A client project with the categories that set the billing rate — the
     * model needs both ids to log time, so they ride along rather than
     * forcing a second call.
     *
     * @return array<string, mixed>
     */
    public function project(Project $project): array
    {
        $categories = [];
        foreach ($project->getCategories() as $service) {
            $categories[] = [
                'id' => (string) $service->getId(),
                'name' => $service->getName(),
                'billingRate' => $service->getBillingRate(),
                'billable' => $service->isBillable(),
            ];
        }

        return [
            'id' => (string) $project->getId(),
            'name' => $project->getName(),
            'currency' => $project->getCurrency(),
            'client' => null === $project->getClient() ? null : [
                'id' => (string) $project->getClient()->getId(),
                'name' => $project->getClient()->getName(),
            ],
            'categories' => $categories,
            'spaceId' => (string) $project->getSpace()?->getId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function client(Client $client): array
    {
        return [
            'id' => (string) $client->getId(),
            'name' => $client->getName(),
            'email' => $client->getEmail(),
            'currency' => $client->getCurrency(),
            'defaultRateAmount' => $client->getDefaultRateAmount(),
            'spaceId' => (string) $client->getSpace()?->getId(),
        ];
    }

    /**
     * @param bool $withLineItems whether to expand the line items — the list
     *                            tool omits them to keep a page of invoices
     *                            inside a sane context budget
     *
     * @return array<string, mixed>
     */
    public function invoice(Invoice $invoice, bool $withLineItems = false): array
    {
        $data = [
            'id' => (string) $invoice->getId(),
            'number' => $invoice->getNumber(),
            'status' => $invoice->getStatus(),
            'currency' => $invoice->getCurrency(),
            'issueDate' => $invoice->getIssueDate()?->format('Y-m-d'),
            'dueDate' => $invoice->getDueDate()?->format('Y-m-d'),
            'client' => null === $invoice->getClient() ? null : [
                'id' => (string) $invoice->getClient()->getId(),
                'name' => $invoice->getClient()->getName(),
            ],
            'subtotal' => $invoice->getSubtotal(),
            'discountAmount' => $invoice->getDiscountAmount(),
            'taxAmount' => $invoice->getTaxAmount(),
            'total' => $invoice->getTotal(),
            'amountPaid' => $invoice->getAmountPaid(),
            'balanceDue' => $invoice->getBalanceDue(),
            'spaceId' => (string) $invoice->getSpace()?->getId(),
        ];

        if ($withLineItems) {
            $lines = [];
            foreach ($invoice->getLineItems() as $line) {
                $lines[] = [
                    'description' => $line->getDescription(),
                    'quantity' => $line->getQuantity(),
                    'unitAmount' => $line->getUnitAmount(),
                    'amount' => $line->getAmount(),
                    'taxRate' => $line->getEffectiveTaxRate(),
                ];
            }
            $data['lineItems'] = $lines;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function expense(Expense $expense): array
    {
        return [
            'id' => (string) $expense->getId(),
            'description' => $expense->getDescription(),
            'amount' => $expense->getAmount(),
            'currency' => $expense->getCurrency(),
            'spentOn' => $expense->getSpentOn()?->format('Y-m-d'),
            'billable' => $expense->isBillable(),
            // Non-null means it's on an invoice and is now frozen.
            'billedAt' => $expense->getBilledAt()?->format(\DateTimeInterface::ATOM),
            'category' => $expense->getCategory(),
            'hasReceipt' => null !== $expense->getReceipt(),
            'project' => $this->projectSummary($expense->getProject()),
            'user' => null === $expense->getUser() ? null : $this->userSummary($expense->getUser()),
            'spaceId' => (string) $expense->getSpace()?->getId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function estimate(Estimate $estimate): array
    {
        return [
            'id' => (string) $estimate->getId(),
            'number' => $estimate->getNumber(),
            'status' => $estimate->getStatus(),
            'currency' => $estimate->getCurrency(),
            'subtotal' => $estimate->getSubtotal(),
            'taxAmount' => $estimate->getTaxAmount(),
            'total' => $estimate->getTotal(),
            'client' => null === $estimate->getClient() ? null : [
                'id' => (string) $estimate->getClient()->getId(),
                'name' => $estimate->getClient()->getName(),
            ],
            'sentAt' => $estimate->getSentAt()?->format(\DateTimeInterface::ATOM),
            'decidedAt' => $estimate->getDecidedAt()?->format(\DateTimeInterface::ATOM),
            // Set once the estimate has been turned into a real invoice.
            'convertedInvoiceId' => null === $estimate->getConvertedInvoice()
                ? null
                : (string) $estimate->getConvertedInvoice()->getId(),
            'spaceId' => (string) $estimate->getSpace()?->getId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function notification(Notification $notification): array
    {
        return [
            'id' => (string) $notification->getId(),
            'type' => $notification->getType(),
            'title' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'read' => $notification->isRead(),
            'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
            // Where this notification points, so the model can follow up
            // without reverse-engineering the target from the body text.
            'targetPath' => $notification->getTargetPath(),
            'targetLabel' => $notification->getTargetLabel(),
            'contextLabel' => $notification->getContextLabel(),
            'actor' => null === $notification->getActor() ? null : $this->userSummary($notification->getActor()),
        ];
    }

    /**
     * One projected calendar occurrence. `occurrenceDate` is the date this
     * instance falls on, which for a recurring task is *not* the task's own
     * due date — that distinction is the whole point of the calendar view.
     *
     * @return array<string, mixed>
     */
    public function calendarOccurrence(Task $task, \DateTimeImmutable $occurrence, bool $recurring): array
    {
        $board = $task->getBoard();

        return [
            'taskId' => (string) $task->getId(),
            'title' => $task->getTitle(),
            'occurrenceDate' => $occurrence->format('Y-m-d'),
            'recurring' => $recurring,
            'dueDate' => $task->getDueDate()?->format(\DateTimeInterface::ATOM),
            'completedOn' => $task->getCompletedOn()?->format(\DateTimeInterface::ATOM),
            'board' => null === $board ? null : $this->boardSummary($board),
            'assignees' => array_map(
                fn (User $assignee) => $this->userSummary($assignee),
                $task->getAssignees()->toArray(),
            ),
        ];
    }
}
