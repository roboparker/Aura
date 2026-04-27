import Head from "next/head";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { GripVertical, Plus, X } from "lucide-react";
import {
  DndContext,
  DragEndEvent,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
} from "@dnd-kit/core";
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

interface Tag {
  "@id": string;
  id: string;
  title: string;
  color: string;
}

interface Task {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
  completedOn: string | null;
  position: number;
  tags: Tag[];
}

interface Collection<T> {
  // API Platform 4 emits JSON-LD 1.1 (`member`); older versions use `hydra:member`.
  member?: T[];
  "hydra:member"?: T[];
}

interface SortableTaskItemProps {
  task: Task;
  allTags: Tag[];
  onToggle: (task: Task) => void;
  onDelete: (task: Task) => void;
  onTagsChange: (task: Task, nextTagIris: string[]) => Promise<void>;
}

const SortableTaskItem = ({
  task,
  allTags,
  onToggle,
  onDelete,
  onTagsChange,
}: SortableTaskItemProps) => {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: task["@id"],
  });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  const attachedIris = new Set(task.tags.map((t) => t["@id"]));
  const availableTags = allTags.filter((t) => !attachedIris.has(t["@id"]));

  const handleRemoveTag = async (tag: Tag) => {
    const next = task.tags.filter((t) => t["@id"] !== tag["@id"]).map((t) => t["@id"]);
    await onTagsChange(task, next);
  };

  const handleAddTag = async (tag: Tag) => {
    const next = [...task.tags.map((t) => t["@id"]), tag["@id"]];
    await onTagsChange(task, next);
  };

  return (
    <li ref={setNodeRef} style={style} data-testid="task-item">
      <Card>
        <CardContent className="pt-4 pb-4 flex items-start gap-3">
          <button
            type="button"
            aria-label={`Drag to reorder "${task.title}"`}
            className="mt-0.5 px-1 text-muted-foreground hover:text-foreground cursor-grab active:cursor-grabbing touch-none bg-transparent border-0"
            {...attributes}
            {...listeners}
          >
            <GripVertical className="h-4 w-4" />
          </button>
          <input
            type="checkbox"
            checked={!!task.completedOn}
            onChange={() => onToggle(task)}
            aria-label={`Mark "${task.title}" as ${task.completedOn ? "incomplete" : "complete"}`}
            className="mt-1 h-4 w-4 shrink-0 cursor-pointer"
          />
          <div className="flex-1 min-w-0">
            <p
              className={cn(
                "font-medium",
                task.completedOn && "line-through text-muted-foreground",
              )}
            >
              {task.title}
            </p>
            {task.description && (
              <MarkdownView
                source={task.description}
                className={cn("mt-1", task.completedOn && "line-through text-muted-foreground")}
              />
            )}
            <div className="mt-2 flex flex-wrap items-center gap-1" data-testid="task-tags">
              {task.tags.map((tag) => (
                <span
                  key={tag["@id"]}
                  className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold text-white"
                  style={{ backgroundColor: tag.color }}
                  data-testid="task-tag"
                >
                  {tag.title}
                  <button
                    type="button"
                    onClick={() => handleRemoveTag(tag)}
                    aria-label={`Remove tag "${tag.title}" from "${task.title}"`}
                    className="ml-0.5 text-white/80 hover:text-white bg-transparent border-0 cursor-pointer leading-none"
                  >
                    <X className="h-3 w-3" />
                  </button>
                </span>
              ))}
              {availableTags.length > 0 && (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <button
                      type="button"
                      aria-label={`Add tag to "${task.title}"`}
                      className="text-xs text-primary hover:underline bg-transparent border border-dashed border-input rounded px-2 py-0.5 cursor-pointer inline-flex items-center gap-1"
                    >
                      <Plus className="h-3 w-3" />
                      Tag
                    </button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="start" className="min-w-[150px] max-h-60 overflow-y-auto">
                    {availableTags.map((tag) => (
                      <DropdownMenuItem
                        key={tag["@id"]}
                        onSelect={() => handleAddTag(tag)}
                      >
                        <span
                          className="inline-block h-3 w-3 rounded shrink-0"
                          style={{ backgroundColor: tag.color }}
                          aria-hidden="true"
                        />
                        <span>{tag.title}</span>
                      </DropdownMenuItem>
                    ))}
                  </DropdownMenuContent>
                </DropdownMenu>
              )}
            </div>
          </div>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => onDelete(task)}
            aria-label={`Delete "${task.title}"`}
            className="text-destructive hover:text-destructive"
          >
            Delete
          </Button>
        </CardContent>
      </Card>
    </li>
  );
};

const Tasks = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [tasks, setTasks] = useState<Task[]>([]);
  const [allTags, setAllTags] = useState<Tag[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  // Bumped after a successful create so the MarkdownEditor remounts with
  // an empty value — its initialContent is only read once at creation.
  const [editorResetKey, setEditorResetKey] = useState(0);

  const sensors = useSensors(
    // Require an 8px drag before activating so a quick click on the grip
    // doesn't get misinterpreted as a reorder attempt.
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.push("/signin");
    }
  }, [authLoading, isAuthenticated, router]);

  const loadData = useCallback(async () => {
    setError(null);
    try {
      // Load tasks and tags in parallel — the task list embeds its current
      // tag badges, the full tag list populates the "+ Tag" picker.
      const [tasksRes, tagsRes] = await Promise.all([
        fetch(`${ENTRYPOINT}/tasks`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        }),
        fetch(`${ENTRYPOINT}/tags`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        }),
      ]);
      if (!tasksRes.ok) {
        throw new Error("Failed to load tasks.");
      }
      if (!tagsRes.ok) {
        throw new Error("Failed to load tags.");
      }
      const tasksData: Collection<Task> = await tasksRes.json();
      const tagsData: Collection<Tag> = await tagsRes.json();
      setTasks(tasksData.member ?? tasksData["hydra:member"] ?? []);
      setAllTags(tagsData.member ?? tagsData["hydra:member"] ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load tasks.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isAuthenticated) {
      loadData();
    }
  }, [isAuthenticated, loadData]);

  const handleCreate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!title.trim()) return;

    setIsSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/tasks`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          title: title.trim(),
          description: description.trim() || null,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to create task.",
        );
      }
      setTitle("");
      setDescription("");
      setEditorResetKey((k) => k + 1);
      await loadData();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create task.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleToggle = async (task: Task) => {
    // Optimistic toggle: flip the checkbox immediately so the UI feels
    // responsive and so controlled-input assertions in tests see the new
    // state without waiting for the server round-trip.
    const previous = tasks;
    const nextCompletedOn = task.completedOn ? null : new Date().toISOString();
    setTasks(
      tasks.map((t) => (t["@id"] === task["@id"] ? { ...t, completedOn: nextCompletedOn } : t)),
    );
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ completedOn: nextCompletedOn }),
      });
      if (!res.ok) {
        throw new Error("Failed to update task.");
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update task.");
    }
  };

  const handleDelete = async (task: Task) => {
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) {
        throw new Error("Failed to delete task.");
      }
      await loadData();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete task.");
    }
  };

  const handleTagsChange = async (task: Task, nextTagIris: string[]) => {
    // Optimistic update so badges appear instantly. Roll back on server reject.
    const previous = tasks;
    const nextTags = nextTagIris
      .map((iri) => allTags.find((t) => t["@id"] === iri))
      .filter((t): t is Tag => Boolean(t));
    setTasks(tasks.map((t) => (t["@id"] === task["@id"] ? { ...t, tags: nextTags } : t)));
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ tags: nextTagIris }),
      });
      if (!res.ok) {
        throw new Error("Failed to update tags.");
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update tags.");
    }
  };

  const handleDragEnd = async (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;

    const oldIndex = tasks.findIndex((t) => t["@id"] === active.id);
    const newIndex = tasks.findIndex((t) => t["@id"] === over.id);
    if (oldIndex === -1 || newIndex === -1) return;

    const previous = tasks;
    const reordered = arrayMove(tasks, oldIndex, newIndex);
    // Apply optimistically — snappy UX, rolled back below if the server rejects.
    setTasks(reordered);
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}/tasks/reorder`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ order: reordered.map((t) => t["@id"]) }),
      });
      if (!res.ok) {
        throw new Error("Failed to save new order.");
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to save new order.");
    }
  };

  if (authLoading || !isAuthenticated) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading...</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Tasks - Aura</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <h1 className="text-2xl font-bold mb-6">Tasks</h1>

          <Card className="mb-6">
            <CardContent className="pt-6">
              <form onSubmit={handleCreate} className="space-y-4">
                <div className="space-y-1.5">
                  <Label htmlFor="title">Title</Label>
                  <Input
                    id="title"
                    type="text"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    required
                    maxLength={255}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="description">
                    Description{" "}
                    <span className="text-muted-foreground font-normal">(optional)</span>
                  </Label>
                  <MarkdownEditor
                    key={editorResetKey}
                    id="description"
                    ariaLabel="Description"
                    value={description}
                    onChange={setDescription}
                  />
                </div>
                <Button type="submit" disabled={isSubmitting || !title.trim()}>
                  {isSubmitting ? "Adding..." : "Add Task"}
                </Button>
              </form>
            </CardContent>
          </Card>

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {isLoading ? (
            <p className="text-muted-foreground">Loading tasks...</p>
          ) : tasks.length === 0 ? (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground">No tasks yet. Add one above to get started.</p>
              </CardContent>
            </Card>
          ) : (
            <DndContext
              sensors={sensors}
              collisionDetection={closestCenter}
              onDragEnd={handleDragEnd}
            >
              <SortableContext
                items={tasks.map((t) => t["@id"])}
                strategy={verticalListSortingStrategy}
              >
                <ul className="space-y-2" data-testid="task-list">
                  {tasks.map((task) => (
                    <SortableTaskItem
                      key={task["@id"]}
                      task={task}
                      allTags={allTags}
                      onToggle={handleToggle}
                      onDelete={handleDelete}
                      onTagsChange={handleTagsChange}
                    />
                  ))}
                </ul>
              </SortableContext>
            </DndContext>
          )}
        </div>
      </div>
    </>
  );
};

export default Tasks;
