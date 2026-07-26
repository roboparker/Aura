<?php

declare(strict_types=1);

namespace App\Automation\Action;

use App\Automation\ActionDefinitionInterface;
use App\Automation\AutomationContext;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tags the task, creating the tag if it doesn't exist yet.
 *
 * Tags are a **shared pool per space**, so the rule works in terms of a name and
 * finds-or-creates within the board's space. Storing a tag id instead would
 * break the rule the moment someone tidied up the tag it pointed at.
 *
 * The find is case-insensitive, so a rule adding "Urgent" doesn't create a
 * second tag alongside an existing "urgent".
 */
final class AddTagAction implements ActionDefinitionInterface
{
    public function type(): string
    {
        return 'task.add_tag';
    }

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function label(): string
    {
        return 'Add a tag';
    }

    public function description(): string
    {
        return 'Tags the task, creating the tag if it does not exist.';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'tag', 'type' => 'text', 'label' => 'Tag name', 'placeholder' => 'needs-review'],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $tag = $config['tag'] ?? null;

        return is_string($tag) && '' !== trim($tag) ? null : 'Enter a tag name.';
    }

    public function execute(AutomationContext $context, array $config): string
    {
        $name = $config['tag'] ?? null;
        if (!is_string($name) || '' === trim($name)) {
            throw new \RuntimeException('No tag configured.');
        }
        $name = trim($name);

        $task = $context->task;
        $space = $task->getBoard()?->getSpace();
        if (null === $space) {
            throw new \RuntimeException('This task is not on a board.');
        }

        foreach ($task->getTags() as $existing) {
            if (mb_strtolower($existing->getTitle()) === mb_strtolower($name)) {
                return sprintf('already tagged "%s"', $existing->getTitle());
            }
        }

        // Case-insensitive, so "Urgent" doesn't spawn a twin of "urgent".
        $tag = null;
        foreach ($this->em->getRepository(Tag::class)->findBy(['space' => $space]) as $candidate) {
            if (mb_strtolower($candidate->getTitle()) === mb_strtolower($name)) {
                $tag = $candidate;
                break;
            }
        }

        if (!$tag instanceof Tag) {
            // The owner is bookkeeping — the pool is the space's — so fall back
            // to the rule's author when the task has none.
            $owner = $task->getOwner() ?? $context->automation?->getCreatedBy();
            if (!$owner instanceof User) {
                throw new \RuntimeException('There is nobody to own the new tag.');
            }

            $tag = (new Tag())->setTitle($name)->setOwner($owner);
            $tag->setSpace($space);
            $this->em->persist($tag);
        }

        $task->addTag($tag);

        return sprintf('tagged "%s"', $name);
    }
}
