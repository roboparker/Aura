# Task organisation: sections, board view, and relationships

Three features structure tasks beyond a flat list: **sections** group a project's tasks, a **Kanban board view** shows those sections as columns, and **relationships** link tasks to one another.

## Sections (#496)

- **`TaskSection`** (entity, table `task_section`) — per-project `title` + `position`, exposed at `/task_sections`. Any space member can CRUD (security via `object.getProject().isAccessibleBy(user)`); the collection is scoped by `TaskSectionAccessExtension`.
- **`Task.section`** — a **nullable** FK (`onDelete: SET NULL`). `null` = the implicit default **"In progress"** group, so existing tasks need no backfill and deleting a section drops its tasks back to the default rather than orphaning them.
- **List view** (`pwa/pages/projects/[id].tsx`): tasks render grouped into per-section tables — each its own table with an editable title, an inline add-row (new tasks land in that section), and a per-section custom-field footer. Grand-total footers bracket the whole board, top and bottom. The "New task" button is a split dropdown with an "Add section" item; each user section has a `…` menu to delete it.
- **Footers per section**: `GET /projects/{id}/custom_field_footers` accepts `?section=<iri|none>` so each section aggregates independently (`none` = the default group; omit the param for the whole board). See the custom-field footer entry in `CLAUDE.md`.

## Board (Kanban) view (#496)

`pwa/components/projects/TaskBoard.tsx` (built on `@dnd-kit`) toggles alongside the list view on the project's Tasks tab. Sections become columns of task cards (title, due date, tags, assignee avatars). A card opens the task drawer on click and **drags between sections** — a drop issues `PATCH /tasks/{id}` with the new `section` IRI. A pointer activation distance keeps a plain click (open drawer) distinct from a drag. The default "In progress" group is the first column.

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

## Tests

- `App\Tests\Api\TaskSectionTest`, `App\Tests\Api\TaskRelationshipTest` (entity access + validation).
- `e2e/tests/*` exercise the project board UI.
