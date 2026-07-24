# Task organisation: sections, board view, and relationships

Three features structure tasks beyond a flat list: **sections** group a board's tasks, a **Kanban board view** shows those sections as columns, and **relationships** link tasks to one another.

## Sections (#496)

- **`TaskSection`** (entity, table `task_section`) — per-board `title` + `position`, exposed at `/task_sections`. Any space member can CRUD (security via `object.getBoard().isAccessibleBy(user)`); the collection is scoped by `TaskSectionAccessExtension`.
- **`Task.section`** — a **nullable** FK (`onDelete: SET NULL`). `null` = the implicit default **"In progress"** group, so existing tasks need no backfill and deleting a section drops its tasks back to the default rather than orphaning them.
- **List view** (`pwa/pages/boards/[id].tsx`): tasks render grouped into per-section tables — each its own table with an editable title, an inline add-row (new tasks land in that section), and a per-section custom-field footer. Grand-total footers bracket the whole board, top and bottom. The "New task" button is a split dropdown with an "Add section" item; each user section has a `…` menu to delete it.
- **Footers per section**: `GET /boards/{id}/custom_field_footers` accepts `?section=<iri|none>` so each section aggregates independently (`none` = the default group; omit the param for the whole board). See the custom-field footer entry in `CLAUDE.md`.

## Board (Kanban) view (#496)

`pwa/components/boards/TaskBoard.tsx` (built on `@dnd-kit`) toggles alongside the list view on the board's Tasks tab. Sections become columns of task cards (title, due date, tags, assignee avatars). A card opens the task drawer on click and **drags between sections** — a drop issues `PATCH /tasks/{id}` with the new `section` IRI. A pointer activation distance keeps a plain click (open drawer) distinct from a drag. The default "In progress" group is the first column.

## Relationships (#497)

- **`TaskRelationship`** (entity, table `task_relationship`) — `source` / `target` Task FKs + `type`, exposed at `/task_relationships`, unique on `(source, target, type)`.
- **Types**: `parent` / `required` / `related` / `duplicate`. One row stores the link from `source` → `target`; the human label is derived per viewing side (`TaskRelationship::labelFor()`):

  | type | from the source | from the target |
  | --- | --- | --- |
  | `parent` | parent of | child of |
  | `required` | required for | required by |
  | `related` | related to | related to |
  | `duplicate` | duplicate of | duplicated by |

  `related` is symmetric — the same label both ways.
- **Validation** (`ValidTaskRelationship`, class-level): rejects self-links, and a same-type duplicate in **either** direction (so `A parent-of B` blocks both a repeat and the reverse `B parent-of A`), while still allowing a *different* type between the same pair.
- **API**: the resource exposes only `Post` / `Get` / `Delete` (security gates on `source` / `target` `isAccessibleBy` — you must be able to reach both tasks to create a link, either to delete one). Reads come through a dedicated endpoint, **`GET /tasks/{id}/relationships`** (`TaskRelationshipController`), which returns every link touching the task from that task's viewpoint — each with the resolved directional label and the other task's summary.
- **PWA**: a **Relationships** section in the task detail drawer (`pwa/components/tasks/TaskRelationshipsPanel.tsx`) — a list grouped by label, an add form (directional type picker + task search), and a per-row remove.

## Subtasks

Subtasks are built **on the `parent` relationship** rather than a separate
model — a subtask is a task that's the `target` of a `parent` link, so the
whole feature reuses `TaskRelationship` with no schema change.

- **Two extra invariants** (in `ValidTaskRelationshipValidator`, `parent` links only):
  - **Single-parent** — a task is the `target` of at most one `parent` link, so
    "the parent" is never ambiguous. Backed by
    `TaskRelationshipRepository::findParentLinkOf()`.
  - **No cycles** — a task can't become a subtask of its own descendant. A BFS
    down the child tree (`findChildLinksOf()`) catches the multi-hop loops that
    the direct-pair reverse-duplicate check can't see.
- **Read**: `GET /tasks/{id}/subtasks` (`TaskRelationshipController::subtasks`)
  returns the ordered children, a `{total, completed}` completion rollup, and
  the task's own `parent` link (so the drawer can show "part of *X*" without a
  second request).
- **Completion never cascades.** A parent is neither auto-completed nor blocked
  by open children — the rollup is informational. (Product decision; the other
  options were considered and rejected.)
- **PWA**: `pwa/components/tasks/SubtasksPanel.tsx` mounts in the task drawer
  above Relationships — a progress bar + `N/M`, a per-child complete checkbox
  (PATCH the child) and unlink control (DELETE the relationship; the child task
  itself survives), and an inline "add subtask" that creates a task on the
  parent's board then links it. A freshly-created task has no parent and no
  descendants, so that link can never trip either invariant.

## Timeline / Gantt view (#timeline)

A board-level Gantt, **opt-in per board**. We followed Notion's model — the
view maps onto existing date fields rather than adding a blanket `Task.startDate`
that every task in every board would carry (Asana/ClickUp/Monday's approach).

- **Bar span**: **start** = a board-configured date custom field; **end** = the
  task's native `dueDate`. Reusing `dueDate` means there's one field to pick and
  no second deadline that could disagree with the real one. A task with a due
  date but no start is a **milestone diamond**; a task with neither is listed as
  *unscheduled* below the chart rather than dropped.
- **Config on `Board`**: a polymorphic pair `timelineStartField` (→
  `CustomFieldDefinition`) / `timelineStartGlobalField` (→
  `GlobalCustomFieldDefinition`), both nullable + `SET NULL`, mirroring
  `CustomFieldValue`. `Board::validateTimeline` (class-level `Assert\Callback`)
  enforces at-most-one, date-kind, and — for a local field — board ownership.
  Read via `getTimelineStartDefinition()` / `hasTimeline()`.
- **Dependencies**: `GET /boards/{id}/dependencies`
  (`BoardTimelineController`) returns the `required` edges among the board's own
  tasks (source→target = predecessor→dependent) for the finish-to-start arrows.
  Edges crossing out of the board are dropped — the other end has no bar.
- **No bar endpoint**: the board page already loads tasks with their custom-field
  values, so start (the mapped field's value) and end (`dueDate`) resolve
  client-side; only the cross-task edges need a query.
- **PWA**: `pwa/components/boards/BoardTimeline.tsx` (generic over the task type)
  — section-grouped rows, day/week/month zoom, a today line, SVG dependency
  arrows, and pointer-drag to move a bar (shifts both dates) or resize an edge
  (start via the custom-field value, end via `dueDate`). Mounts as a **Timeline**
  tab on `pwa/pages/boards/[id].tsx` next to List/Board/Calendar, empty until
  configured; the start-field picker lives under **Settings → Timeline**.

## Tests

- `App\Tests\Api\TaskSectionTest`, `App\Tests\Api\TaskRelationshipTest` (entity access + validation).
- `App\Tests\Api\BoardTimelineTest` (start-field config validation + dependency-edge scoping).
- `e2e/tests/*` exercise the board UI, including the Timeline tab mount.
