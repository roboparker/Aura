<?php

declare(strict_types=1);

namespace App\Automation\Action;

use App\Automation\ActionDefinitionInterface;
use App\Automation\AutomationContext;
use App\Entity\Comment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Posts a comment on the task.
 *
 * `Comment.author` can't be null, so a rule-written comment has to be
 * attributed to somebody. It uses the **rule's author** — the person who chose
 * for this to happen — falling back to the task owner if that account is gone.
 *
 * It also **says it was automated**. A comment appearing under someone's name
 * with words they never typed is the kind of small dishonesty that erodes trust
 * in a whole feature, so the attribution line is appended rather than left to
 * the author's discretion.
 */
final class AddCommentAction implements ActionDefinitionInterface
{
    /** Leaves generous room under Comment::MAX_BODY_LENGTH for the suffix. */
    private const MAX_BODY = 5000;

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function type(): string
    {
        return 'task.comment';
    }

    public function label(): string
    {
        return 'Post a comment';
    }

    public function description(): string
    {
        return 'Adds a comment to the task, marked as posted by this rule.';
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'body',
                'type' => 'textarea',
                'label' => 'Comment',
                'placeholder' => 'This task moved to review — please take a look.',
            ],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $body = $config['body'] ?? null;
        if (!is_string($body) || '' === trim($body)) {
            return 'Write the comment to post.';
        }

        return mb_strlen($body) <= self::MAX_BODY
            ? null
            : sprintf('Keep the comment under %d characters.', self::MAX_BODY);
    }

    public function execute(AutomationContext $context, array $config): string
    {
        $body = $config['body'] ?? null;
        if (!is_string($body) || '' === trim($body)) {
            throw new \RuntimeException('No comment configured.');
        }

        $author = $context->automation?->getCreatedBy() ?? $context->task->getOwner();
        if (!$author instanceof User) {
            throw new \RuntimeException('There is nobody to post this comment as.');
        }

        $ruleName = $context->automation?->getName() ?? 'a rule';
        $comment = (new Comment())
            ->setTask($context->task)
            ->setAuthor($author)
            ->setBody(sprintf("%s\n\n_Posted automatically by the rule “%s”._", trim($body), $ruleName));

        $this->em->persist($comment);

        return 'posted a comment';
    }
}
