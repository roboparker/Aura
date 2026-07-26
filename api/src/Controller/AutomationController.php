<?php

declare(strict_types=1);

namespace App\Controller;

use App\Automation\ActionDefinitionInterface;
use App\Automation\AutomationRegistry;
use App\Automation\AutomationValidator;
use App\Automation\ConditionDefinitionInterface;
use App\Automation\TriggerDefinitionInterface;
use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Entity\Board;
use App\Entity\User;
use App\Repository\AutomationRepository;
use App\Repository\AutomationRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Board automation rules (#764).
 *
 * **Editing is space-admin.** A rule mutates other people's tasks, so it sits
 * with custom fields rather than with ordinary board edits. Reading is open to
 * any space member who can see the board — including the run log, since it's
 * what explains a change someone didn't make to their own task.
 *
 * Not an `ApiResource`: every write parses and type-checks the graph against
 * the registry, which reads far better here than split across processors and
 * validators.
 */
class AutomationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AutomationRepository $automations,
        private AutomationRunRepository $runs,
        private AutomationRegistry $registry,
        private AutomationValidator $validator,
    ) {
    }

    /** The palette: everything this server can put on a canvas. */
    #[Route('/boards/{id}/automations/catalog', name: 'board_automation_catalog', methods: ['GET'])]
    public function catalog(string $id, #[CurrentUser] ?User $user): Response
    {
        [$board, $error] = $this->resolveBoard($id, $user);
        if (null !== $error) {
            return $error;
        }

        return $this->json([
            'triggers' => array_map(
                fn (TriggerDefinitionInterface $t) => [
                    'type' => $t->type(),
                    'label' => $t->label(),
                    'description' => $t->description(),
                    'event' => $t->event(),
                    'configSchema' => $t->configSchema(),
                ],
                $this->registry->allTriggers(),
            ),
            'conditions' => array_map(
                fn (ConditionDefinitionInterface $c) => [
                    'type' => $c->type(),
                    'label' => $c->label(),
                    'description' => $c->description(),
                    'configSchema' => $c->configSchema(),
                ],
                $this->registry->allConditions(),
            ),
            'actions' => array_map(
                fn (ActionDefinitionInterface $a) => [
                    'type' => $a->type(),
                    'label' => $a->label(),
                    'description' => $a->description(),
                    'configSchema' => $a->configSchema(),
                ],
                $this->registry->allActions(),
            ),
        ]);
    }

    #[Route('/boards/{id}/automations', name: 'board_automations', methods: ['GET'])]
    public function index(string $id, #[CurrentUser] ?User $user): Response
    {
        [$board, $error] = $this->resolveBoard($id, $user);
        if (null !== $error) {
            return $error;
        }
        assert($board instanceof Board && $user instanceof User);

        return $this->json([
            'canEdit' => $board->isSpaceAdmin($user),
            'automations' => array_map(
                fn (Automation $a) => $this->serialize($a),
                $this->automations->forBoard($board),
            ),
        ]);
    }

    #[Route('/boards/{id}/automations', name: 'board_automation_create', methods: ['POST'])]
    public function create(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        [$board, $error] = $this->resolveBoard($id, $user, requireAdmin: true);
        if (null !== $error) {
            return $error;
        }
        assert($board instanceof Board && $user instanceof User);

        if ($this->automations->countForBoard($board) >= Automation::MAX_PER_BOARD) {
            return $this->json([
                'error' => sprintf('A board can hold at most %d rules.', Automation::MAX_PER_BOARD),
            ], 422);
        }

        $payload = $request->toArray();
        $graph = $payload['graph'] ?? [];
        if (!is_array($graph)) {
            return $this->json(['error' => 'A rule needs a graph.'], 422);
        }

        try {
            /** @var array<string, mixed> $graph */
            $checked = $this->validator->validate($graph);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        $rule = (new Automation())
            ->setBoard($board)
            ->setName($this->nameFrom($payload))
            ->setTriggerEvent($checked['event'])
            ->setGraph($graph)
            ->setCreatedBy($user);

        if (array_key_exists('enabled', $payload)) {
            $rule->setEnabled(true === $payload['enabled']);
        }

        $this->em->persist($rule);
        $this->em->flush();

        return $this->json($this->serialize($rule), 201);
    }

    #[Route('/automations/{automationId}', name: 'automation_update', methods: ['PATCH'])]
    public function update(string $automationId, Request $request, #[CurrentUser] ?User $user): Response
    {
        [$rule, $error] = $this->resolveRule($automationId, $user, requireAdmin: true);
        if (null !== $error) {
            return $error;
        }
        assert($rule instanceof Automation);

        $payload = $request->toArray();

        if (array_key_exists('graph', $payload)) {
            $graph = $payload['graph'];
            if (!is_array($graph)) {
                return $this->json(['error' => 'A rule needs a graph.'], 422);
            }

            try {
                /** @var array<string, mixed> $graph */
                $checked = $this->validator->validate($graph);
            } catch (\InvalidArgumentException $e) {
                return $this->json(['error' => $e->getMessage()], 422);
            }

            $rule->setGraph($graph);
            // The denormalised event has to move with the graph, or the rule
            // would keep listening for whatever it used to trigger on.
            $rule->setTriggerEvent($checked['event']);
        }

        if (array_key_exists('name', $payload)) {
            $rule->setName($this->nameFrom($payload));
        }
        if (array_key_exists('enabled', $payload)) {
            $rule->setEnabled(true === $payload['enabled']);
        }

        $this->em->flush();

        return $this->json($this->serialize($rule));
    }

    #[Route('/automations/{automationId}', name: 'automation_delete', methods: ['DELETE'])]
    public function remove(string $automationId, #[CurrentUser] ?User $user): Response
    {
        [$rule, $error] = $this->resolveRule($automationId, $user, requireAdmin: true);
        if (null !== $error) {
            return $error;
        }
        assert($rule instanceof Automation);

        $this->em->remove($rule);
        $this->em->flush();

        return new Response(null, 204);
    }

    /**
     * Recent runs. Readable by any space member — this is what answers "why did
     * my task change", and the person asking is usually not an admin.
     */
    #[Route('/automations/{automationId}/runs', name: 'automation_runs', methods: ['GET'])]
    public function runs(string $automationId, #[CurrentUser] ?User $user): Response
    {
        [$rule, $error] = $this->resolveRule($automationId, $user);
        if (null !== $error) {
            return $error;
        }
        assert($rule instanceof Automation);

        return $this->json([
            'runs' => array_map(
                fn (AutomationRun $run) => [
                    'id' => (string) $run->getId(),
                    'status' => $run->getStatus(),
                    'taskTitle' => $run->getTaskTitle(),
                    'taskId' => null === $run->getTask() ? null : (string) $run->getTask()->getId(),
                    'depth' => $run->getDepth(),
                    'steps' => $run->getSteps(),
                    'ranAt' => $run->getRanAt()->format(\DATE_ATOM),
                ],
                $this->runs->recentFor($rule),
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(Automation $rule): array
    {
        return [
            'id' => (string) $rule->getId(),
            'name' => $rule->getName(),
            'enabled' => $rule->isEnabled(),
            'triggerEvent' => $rule->getTriggerEvent(),
            'graph' => $rule->getGraph(),
            'lastError' => $rule->getLastError(),
            'lastRunAt' => $rule->getLastRunAt()?->format(\DATE_ATOM),
        ];
    }

    /** @param array<int|string, mixed> $payload */
    private function nameFrom(array $payload): string
    {
        $name = $payload['name'] ?? null;
        $name = is_string($name) ? trim($name) : '';

        return '' === $name
            ? 'Untitled rule'
            : mb_substr($name, 0, Automation::MAX_NAME_LENGTH);
    }

    /**
     * @return array{0: Board|null, 1: Response|null}
     */
    private function resolveBoard(string $id, ?User $user, bool $requireAdmin = false): array
    {
        if (null === $user) {
            return [null, $this->json(['error' => 'Not authenticated.'], 401)];
        }
        if (!Uuid::isValid($id)) {
            return [null, $this->json(['error' => 'Board not found.'], 404)];
        }

        $board = $this->em->getRepository(Board::class)->find($id);
        // Existence-hiding 404, matching the access extensions.
        if (null === $board || !$board->isAccessibleBy($user)) {
            return [null, $this->json(['error' => 'Board not found.'], 404)];
        }

        if ($requireAdmin && !$board->isSpaceAdmin($user)) {
            return [null, $this->json(['error' => 'Only space admins can change automations.'], 403)];
        }

        return [$board, null];
    }

    /**
     * @return array{0: Automation|null, 1: Response|null}
     */
    private function resolveRule(string $id, ?User $user, bool $requireAdmin = false): array
    {
        if (null === $user) {
            return [null, $this->json(['error' => 'Not authenticated.'], 401)];
        }
        if (!Uuid::isValid($id)) {
            return [null, $this->json(['error' => 'Rule not found.'], 404)];
        }

        $rule = $this->em->getRepository(Automation::class)->find($id);
        $board = $rule?->getBoard();
        if (null === $rule || null === $board || !$board->isAccessibleBy($user)) {
            return [null, $this->json(['error' => 'Rule not found.'], 404)];
        }

        if ($requireAdmin && !$board->isSpaceAdmin($user)) {
            return [null, $this->json(['error' => 'Only space admins can change automations.'], 403)];
        }

        return [$rule, null];
    }
}
