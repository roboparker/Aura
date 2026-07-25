<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Project;
use App\Entity\User;
use App\Mcp\McpBillingAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records an expense against a client project. Receipts aren't attachable here
 * — uploading one is a separate multipart flow (`upload_file`), and an expense
 * without a receipt is a legitimate row that can gain one later in the UI.
 */
final class LogExpenseTool implements McpToolInterface
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
        return 'log_expense';
    }

    public function getDescription(): string
    {
        return 'Record an expense against a client project. Amount is in minor units of the project currency (e.g. 1250 = $12.50). Optionally pass a categoryId from the space\'s managed expense categories.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'projectId' => ['type' => 'string', 'description' => 'UUID of the client project (from list_projects).'],
                'amount' => ['type' => 'integer', 'description' => 'Cost in minor units (cents). Must be positive.'],
                'spentOn' => ['type' => 'string', 'description' => 'Date of the spend, Y-m-d. Defaults to today.'],
                'description' => ['type' => 'string', 'description' => 'What the money was spent on.'],
                'categoryId' => ['type' => 'string', 'description' => 'UUID of a managed expense category in the space.'],
                'billable' => ['type' => 'boolean', 'description' => 'Whether the client is rebilled for it. Defaults to true.'],
            ],
            'required' => ['projectId', 'amount'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $projectId = $this->input->requireUuid('projectId', $this->input->requireString($arguments, 'projectId'));

        $amount = $this->input->optionalInt($arguments, 'amount');
        if (null === $amount || $amount <= 0) {
            throw McpException::invalidInput('amount must be a positive integer in minor units (e.g. 1250 for $12.50).');
        }

        $project = $this->em->getRepository(Project::class)->find($projectId);
        // Expenses ride the time_entries permission, matching the REST rules.
        if (null === $project || !$this->authz->canTrackTimeIn($project->getSpace(), $user)) {
            throw McpException::notFound(sprintf('Project %s', $projectId));
        }

        $expense = new Expense();
        $expense->setProject($project);
        $expense->setSpace($project->getSpace());
        $expense->setUser($user);
        $expense->setAmount($amount);
        $expense->setCurrency($project->getCurrency());
        $expense->setSpentOn($this->input->optionalDateTime($arguments, 'spentOn') ?? new \DateTimeImmutable('today'));
        $expense->setBillable(true !== ($arguments['billable'] ?? true) ? false : true);

        $description = $this->input->optionalString($arguments, 'description');
        if (null !== $description) {
            $expense->setDescription($description);
        }

        $categoryId = $this->input->optionalUuid('categoryId', $arguments['categoryId'] ?? null);
        if (null !== $categoryId) {
            $category = $this->em->getRepository(ExpenseCategory::class)->find($categoryId);
            if (null === $category || true !== $category->getSpace()?->getId()?->equals($project->getSpace()?->getId())) {
                throw McpException::notFound(sprintf('Expense category %s', $categoryId));
            }
            $expense->setExpenseCategory($category);
        }

        $this->input->assertValid($expense);
        $this->em->persist($expense);
        $this->em->flush();

        return $this->serializer->expense($expense);
    }
}
