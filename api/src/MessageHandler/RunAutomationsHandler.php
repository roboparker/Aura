<?php

namespace App\MessageHandler;

use App\Automation\AutomationContext;
use App\Automation\AutomationEvents;
use App\Automation\AutomationRunner;
use App\Automation\TaskSnapshot;
use App\Entity\AutomationRun;
use App\Entity\Board;
use App\Entity\Task;
use App\Entity\User;
use App\Message\RunAutomations;
use App\Repository\AutomationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs every rule on a board that listens for one change (#764).
 *
 * **Rules can chain.** If a rule actually changes the task, the handler
 * dispatches a follow-up `task.updated` at `depth + 1`, so a rule watching the
 * new state gets its turn — the "move to Done, then a second rule archives it"
 * case people expect.
 *
 * That is exactly where the depth limit earns its keep. The follow-up carries a
 * real before/after diff (see {@see TaskSnapshot}) so downstream triggers can
 * still tell what changed, and `AutomationContext::MAX_DEPTH` bounds the chain
 * — two rules that edit each other run three times and then stop, instead of
 * running until the worker dies.
 */
#[AsMessageHandler]
final class RunAutomationsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private AutomationRepository $automations,
        private AutomationRunner $runner,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunAutomations $message): void
    {
        $deleted = AutomationEvents::TASK_DELETED === $message->event;

        if ($deleted) {
            // The row is gone on purpose. Rebuild a detached shell from what
            // travelled with the message so conditions can still read what the
            // task *was* — the runner refuses to let anything write to it.
            $board = null === $message->boardId
                ? null
                : $this->em->getRepository(Board::class)->find($message->boardId);
            if (!$board instanceof Board) {
                return;
            }

            $task = new Task();
            $task->setTitle($message->taskTitle ?? 'Deleted task');
            $task->setBoard($board);
        } else {
            $task = $this->em->getRepository(Task::class)->find($message->taskId);
            // Deleted between the edit and the worker picking this up. Nothing
            // to do, and not an error worth retrying.
            if (!$task instanceof Task) {
                return;
            }

            $board = $task->getBoard();
            if (null === $board) {
                return;
            }
        }

        $rules = $this->automations->enabledFor($board, $message->event);
        if ([] === $rules) {
            return;
        }

        $actor = null === $message->actorId
            ? null
            : $this->em->getRepository(User::class)->find($message->actorId);

        $context = new AutomationContext(
            task: $task,
            event: $message->event,
            before: $message->before,
            after: $message->after,
            actor: $actor instanceof User ? $actor : null,
            depth: $message->depth,
            taskDeleted: $deleted,
        );

        // Snapshot before the rules touch anything, so the follow-up event can
        // describe what they actually changed rather than guessing.
        $beforeRules = TaskSnapshot::of($task);

        $applied = false;
        foreach ($rules as $rule) {
            try {
                $run = $this->runner->run($rule, $context);
            } catch (\Throwable $e) {
                // One rule blowing up must not stop the others on the board.
                $this->logger->error('Automation failed.', [
                    'automation' => (string) $rule->getId(),
                    'task' => $message->taskId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (null !== $run && AutomationRun::STATUS_APPLIED === $run->getStatus()) {
                $applied = true;
            }
        }

        $this->em->flush();

        // A deletion can't chain: nothing was changed, and there's no task left
        // for a follow-up rule to look at.
        if (!$applied || $deleted) {
            return;
        }

        $afterRules = TaskSnapshot::of($task);
        if (!TaskSnapshot::differs($beforeRules, $afterRules)) {
            // A rule "applied" but changed nothing observable — a comment, say.
            // Not worth waking every rule on the board again.
            return;
        }

        $nextDepth = $message->depth + 1;
        if ($nextDepth >= AutomationContext::MAX_DEPTH) {
            // The chain is deliberately cut here rather than left to the
            // runner's per-action check, so we don't keep enqueuing jobs that
            // are guaranteed to do nothing.
            $this->logger->info('Automation chain stopped at the depth limit.', [
                'task' => $message->taskId,
                'depth' => $nextDepth,
            ]);

            return;
        }

        $this->bus->dispatch(new RunAutomations(
            taskId: $message->taskId,
            event: AutomationEvents::TASK_UPDATED,
            before: $beforeRules,
            after: $afterRules,
            actorId: $message->actorId,
            depth: $nextDepth,
        ));
    }
}
