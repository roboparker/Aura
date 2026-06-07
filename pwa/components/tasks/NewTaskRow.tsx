import { useEffect, useRef, useState } from "react";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import AssigneesCombobox, {
  type AssigneeOption,
} from "@/components/tasks/AssigneesCombobox";
import TagsCombobox from "@/components/tasks/TagsCombobox";
import DueDateCell from "@/components/tasks/DueDateCell";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { TableCell, TableRow } from "@/components/ui/table";
import { plainTextDescription, type Tag } from "@/components/tasks/taskHelpers";

export interface NewTaskInput {
  title: string;
  description: string | null;
  tags: string[];
  dueDate: string | null;
  assignees: string[];
}

interface NewTaskRowProps {
  allTags: Tag[];
  assignableUsers: AssigneeOption[];
  /** Resolves on success, rejects on failure so we know whether to clear the draft. */
  onCreate: (input: NewTaskInput) => Promise<void>;
  isCreating: boolean;
  currentUserIri: string | null;
  /** When true, new tasks default to being assigned to the current user — used
   *  on /my-tasks so a freshly created row doesn't immediately filter itself
   *  out. */
  autoAssignSelf?: boolean;
}

// Inline "add a task" row that lives at the top of the table. Mirrors the
// layout of TaskRow (main row + description sub-row) so the user can stage
// title, tags, and description before pressing Enter to submit. Failures
// keep the draft intact (parent shows the error).
const NewTaskRow = ({
  allTags,
  assignableUsers,
  onCreate,
  isCreating,
  currentUserIri,
  autoAssignSelf,
}: NewTaskRowProps) => {
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState<string | null>(null);
  const [tags, setTags] = useState<Tag[]>([]);
  const [dueDate, setDueDate] = useState<string | null>(null);
  // Personal tasks (no project) only allow the owner. Restrict the picker to
  // self so we don't surface teammates the validator would reject.
  const newTaskAssignableUsers = useMemo(
    () => assignableUsers.filter((u) => u["@id"] === currentUserIri),
    [assignableUsers, currentUserIri],
  );
  const selfOption = newTaskAssignableUsers[0] ?? null;
  const [assignees, setAssignees] = useState<AssigneeOption[]>(() =>
    autoAssignSelf && selfOption ? [selfOption] : [],
  );
  // If the assignable list arrives after mount (async), seed the default
  // assignment then. Subsequent changes are user-driven, so only seed once.
  const seededRef = useRef(false);
  useEffect(() => {
    if (seededRef.current) return;
    if (autoAssignSelf && selfOption && assignees.length === 0) {
      setAssignees([selfOption]);
      seededRef.current = true;
    }
  }, [autoAssignSelf, selfOption, assignees.length]);
  const titleInputRef = useRef<HTMLInputElement | null>(null);

  // Description inline editing — local-only; nothing hits the API until
  // submit. Mirrors TaskRow's editing state machine.
  const [editingDesc, setEditingDesc] = useState(false);
  const [descDraft, setDescDraft] = useState("");
  const [descEditorKey, setDescEditorKey] = useState(0);

  const startEditDesc = () => {
    setDescDraft(description ?? "");
    setDescEditorKey((k) => k + 1);
    setEditingDesc(true);
  };
  const cancelDesc = () => {
    setDescDraft(description ?? "");
    setEditingDesc(false);
  };
  const saveDesc = () => {
    setEditingDesc(false);
    const trimmed = descDraft.trim();
    setDescription(trimmed === "" ? null : descDraft);
  };

  const handleTagsChange = (nextIris: string[]) => {
    const next = nextIris
      .map((iri) => allTags.find((tag) => tag["@id"] === iri))
      .filter((tag): tag is Tag => Boolean(tag));
    setTags(next);
  };

  const handleAssigneesChange = (nextIris: string[]) => {
    const next = nextIris
      .map((iri) => assignableUsers.find((u) => u["@id"] === iri))
      .filter((u): u is AssigneeOption => Boolean(u));
    setAssignees(next);
  };

  const reset = () => {
    setTitle("");
    setDescription(null);
    setTags([]);
    setDueDate(null);
    setAssignees(autoAssignSelf && selfOption ? [selfOption] : []);
    setEditingDesc(false);
    setDescDraft("");
  };

  const submit = async () => {
    const trimmed = title.trim();
    if (!trimmed) return;
    try {
      await onCreate({
        title: trimmed,
        description,
        tags: tags.map((tag) => tag["@id"]),
        dueDate,
        assignees: assignees.map((u) => u["@id"]),
      });
      reset();
      // Refocus on next tick so the input isn't briefly disabled when we
      // try to focus it.
      requestAnimationFrame(() => titleInputRef.current?.focus());
    } catch {
      // Parent has already surfaced the error in the alert region; keep
      // the draft so the user can retry without retyping.
    }
  };

  const descriptionPreview = plainTextDescription(description);

  return (
    <tbody data-testid="new-task-row">
      <TableRow className="border-b-0 hover:bg-transparent">
        <TableCell className="w-8" aria-hidden="true" />
        <TableCell className="w-10" aria-hidden="true" />
        <TableCell className="pl-0 align-top">
          <Input
            ref={titleInputRef}
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault();
                void submit();
              } else if (e.key === "Escape") {
                e.preventDefault();
                reset();
                titleInputRef.current?.blur();
              }
            }}
            placeholder="Add a task…"
            maxLength={255}
            disabled={isCreating}
            aria-label="New task title"
            className="h-8"
            data-testid="new-task-title-input"
          />
        </TableCell>
        <TableCell className="align-top" data-testid="new-task-due">
          <DueDateCell
            value={dueDate}
            onChange={(next) => setDueDate(next)}
            ariaLabel="Due date for new task"
            testIdPrefix="new-task-due-date"
          />
        </TableCell>
        <TableCell className="align-top" data-testid="new-task-tags">
          <TagsCombobox
            value={tags}
            options={allTags}
            onChange={handleTagsChange}
            subjectLabel="new task"
          />
        </TableCell>
        <TableCell className="align-top" data-testid="new-task-assignees">
          <AssigneesCombobox
            value={assignees}
            options={newTaskAssignableUsers}
            onChange={handleAssigneesChange}
            subjectLabel="new task"
          />
        </TableCell>
        <TableCell aria-hidden="true" />
      </TableRow>
      <TableRow
        className="hover:bg-transparent"
        data-testid="new-task-description-row"
      >
        <TableCell className="w-8" aria-hidden="true" />
        <TableCell className="w-10" aria-hidden="true" />
        <TableCell colSpan={5} className="pl-0 pr-4 pt-0 pb-3 text-sm">
          {editingDesc ? (
            <div className="space-y-2">
              <MarkdownEditor
                key={descEditorKey}
                ariaLabel="Description for new task"
                value={descDraft}
                onChange={setDescDraft}
              />
              <div className="flex gap-2">
                <Button
                  size="sm"
                  type="button"
                  onClick={saveDesc}
                  data-testid="new-task-description-save"
                >
                  Save
                </Button>
                <Button
                  size="sm"
                  type="button"
                  variant="ghost"
                  onClick={cancelDesc}
                >
                  Cancel
                </Button>
              </div>
            </div>
          ) : descriptionPreview ? (
            <button
              type="button"
              onClick={startEditDesc}
              aria-label="Edit description for new task"
              className="text-left w-full text-muted-foreground whitespace-pre-wrap rounded-sm hover:text-foreground"
              data-testid="new-task-description"
            >
              {descriptionPreview}
            </button>
          ) : (
            <button
              type="button"
              onClick={startEditDesc}
              aria-label="Add description for new task"
              className="text-left w-full italic text-muted-foreground/60 hover:text-muted-foreground rounded-sm"
              data-testid="new-task-description-add"
            >
              Add description
            </button>
          )}
        </TableCell>
      </TableRow>
    </tbody>
  );
};

export default NewTaskRow;
