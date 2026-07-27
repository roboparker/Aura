# Board automations

Rules on a board: **when** something happens, **only if** some condition holds, **then** do something. Built on a node canvas, run server-side, asynchronously.

- Issue: [#764](https://github.com/roboparker/Aura/issues/764)
- Backend: `api/src/Automation/`
- Frontend: `pwa/components/automations/`

## Graph semantics

A rule is a directed graph with exactly **one trigger**, which is the root.

**A condition is a gate, not a branch.** Its downstream subtree runs only if it passes; there is no true/false pair of outputs. For an either/or, hang two conditions off the same parent. This keeps the model small and makes "the branch under a failed condition didn't run" easy to reason about when a rule misbehaves overnight.

Actions run in edge order. Multiple edges from one node fan out.

```
[Task completed]
      ├──▶ ◇ tag is "urgent" ──▶ ■ notify the lead
      └──▶ ■ move to "Done"
```

Stored as JSON: `{nodes: [{id, kind, type, config, position}], edges: [{from, to}]}`. `position` is canvas layout — the server parses the graph and ignores that key entirely, so layout travels with the rule without the backend knowing what a canvas is.

## Loops: three defences, not one

An action that edits a task can re-trigger rules. Any single defence here is brittle, so there are three:

1. **Cycles rejected at save.** DFS in `AutomationGraph`; a rule that can reach itself never persists.
2. **A depth limit across rules.** This is the important one. When a rule changes a task, the handler dispatches a follow-up at `depth + 1`, so rules can chain — and `AutomationContext::MAX_DEPTH` bounds it. The limit bites *at the point of mutation*: a rule past it may still evaluate but may not change anything. No per-graph check can see rule A ↔ rule B; only a counter that survives across executions can.
3. **A step budget** in the runner, bounding fan-out within one walk.

The due-date sweep has its own version of this problem: it selects only tasks that went overdue **in the last few hours**, not everything overdue. Selecting everything would re-fire hourly for the life of the task — the usual way an automation feature becomes a notification firehose.

## Execution

Async through Messenger. `AutomationDispatcher` is the only thing request-path code calls; it checks for a matching enabled rule before enqueuing, because most boards have none and a job that finds nothing is pure cost.

The **before/after snapshot travels with the message** (`TaskSnapshot`). By the time the worker runs, the task may have changed again — asking "did the section just change" of current state would answer a different question than the one that fired the rule.

**Failure is per-step.** One action throwing records an error against that node and the walk continues with its siblings. A rule that assigns *and* comments shouldn't lose the assignment because someone deleted a tag.

Events (`AutomationEvents`): `task.created`, `task.updated`, `task.completed`, `task.due`, `task.deleted`. Completion raises its own event rather than a generic update, so a "when completed" rule can't re-fire on every later edit of a finished task.

### `task.deleted` is the odd one

By the time rules run, the row is gone. That breaks the usual model, so it's handled explicitly rather than left to fail confusingly:

- The message carries the **board id and the task's title**, because the board can't be resolved from a task that no longer exists.
- The handler rebuilds a **detached shell** from that snapshot so conditions can still read what the task *was*.
- Actions must be marked `SurvivesTaskDeletion` to run. Everything else is **skipped with an explanation in the run log** — letting `add_tag` mutate a detached object would look like success while doing nothing.
- The run is logged with `task = null` and the title kept in `taskTitle`. Linking the FK would make Doctrine try to persist the deleted task back.
- Deletions never chain: there's nothing left to change.

In practice this means `task.deleted` pairs with **Notify someone**, which is why that action exists.

## Adding a trigger, condition, or action

One PHP class plus one entry in the client catalog — no migration, no route, no engine edit. The tag is applied automatically by `_instanceof` in `services.yaml`.

```php
final class TaskPriorityRaisedTrigger implements TriggerDefinitionInterface
{
    public function type(): string { return 'task.priority_raised'; }
    public function label(): string { return 'Priority is raised'; }
    public function event(): string { return AutomationEvents::TASK_UPDATED; }
    public function configSchema(): array { return []; }
    public function validateConfig(array $config): ?string { return null; }

    public function matches(AutomationContext $context, array $config): bool
    {
        return $context->changed('priority');
    }
}
```

The canvas builds its palette from `GET /boards/{id}/automations/catalog`, and renders config editors from each step's `configSchema`, so a new step appears with a working editor and **no client change at all** — provided its fields use the shared control vocabulary (`text`, `textarea`, `number`, `select`, `boolean`, `section`, `space_member`).

## Rules for implementations

- **Actions confine themselves to their board, and fail closed.** Config is user-supplied. An action resolving a user id checks space membership; one resolving a section checks the section is on *this* board. Throw rather than skipping quietly — the run log will show why. Without this a rule could assign work to a stranger or move a task onto a board it isn't part of, and nothing else would catch it.
- **Conditions read current state; triggers read the change.** "Is this overdue" is a question about the task; "did the due date just change" is a trigger's job. Keeping that split means a condition behaves the same wherever it's hung in the graph.
- **Tolerate deleted targets.** A condition pointing at a removed tag should read as "no match", not throw on every run.
- **Don't flush.** `AutomationRunner` owns the transaction.
- **Say when you're automated.** The comment action attributes to the rule's author *and* appends a line saying a rule posted it. A comment under someone's name containing words they never typed is a small dishonesty that would undermine the feature.

## Permissions

- **Writing a rule is space admin.** It mutates other people's tasks, so it sits with custom fields rather than ordinary board edits.
- **Reading — including the run log — is any space member** who can see the board. The log is what explains a change someone didn't make to their own task, and that person is usually not an admin.

## Run log

Every execution writes an `AutomationRun` with per-node outcomes (`passed` / `skipped` / `done` / `error`). This exists because "why did my task change?" and "why didn't my rule fire?" are the first two questions anyone asks of a rules engine, and neither is answerable without it.

A non-zero `depth` means another rule caused the change — the first thing to check when rules appear to be fighting.

Pruned nightly to `app.automation_run_retention_days` (default 30). It's a debugging aid, not an audit trail.

## Endpoints

| Method | Path | Who |
|---|---|---|
| GET | `/boards/{id}/automations` | any board reader |
| GET | `/boards/{id}/automations/catalog` | any board reader |
| POST | `/boards/{id}/automations` | space admin |
| PATCH | `/automations/{id}` | space admin |
| DELETE | `/automations/{id}` | space admin |
| GET | `/automations/{id}/runs` | any board reader |

Writes are type-checked against the registry (`AutomationValidator`), not just shape-checked: a rule referencing a step this server doesn't have is refused at write time, with the offending step named. Storing a rule that can never run is worse than refusing it, because it looks like it works.

## Canvas

React Flow, dynamically imported, mounted only when the tab is open. Cycles and edges-into-the-trigger are refused as they're drawn rather than on save — the server rejects them either way, but finding out after arranging a whole rule is miserable.

New rules start **disabled**: one that begins firing the instant it's created, before it has any actions, is a nasty surprise.

Below the `lg` breakpoint the canvas is read-only, with a line saying why. Drag-and-connect on a phone is bad enough that pretending otherwise is worse.

## Tests

`AutomationGraphTest` (shape + cycles), `AutomationRunnerTest` (execution, depth limit, fail-closed confinement), `AutomationWiringTest` (real HTTP end to end), `AutomationCrudTest` (permissions + validation), `pwa/lib/automationTypes.test.ts`.
