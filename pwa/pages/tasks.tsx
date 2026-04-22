import Head from "next/head";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
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
import { useAuth } from "../contexts/AuthContext";
import { ENTRYPOINT } from "../config/entrypoint";

interface Task {
  "@id": string;
  id: number;
  title: string;
  description: string | null;
  createdOn: string;
  completedOn: string | null;
  position: number;
}

interface TaskCollection {
  // API Platform 4 emits JSON-LD 1.1 (`member`); older versions use `hydra:member`.
  member?: Task[];
  "hydra:member"?: Task[];
}

interface SortableTaskItemProps {
  task: Task;
  onToggle: (task: Task) => void;
  onDelete: (task: Task) => void;
}

const SortableTaskItem = ({ task, onToggle, onDelete }: SortableTaskItemProps) => {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: task["@id"],
  });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  return (
    <li
      ref={setNodeRef}
      style={style}
      className="bg-white rounded-lg shadow-card p-4 flex items-start gap-3"
      data-testid="task-item"
    >
      <button
        type="button"
        aria-label={`Drag to reorder "${task.title}"`}
        className="mt-0.5 px-1 text-gray-400 hover:text-gray-600 cursor-grab active:cursor-grabbing touch-none bg-transparent border-0"
        {...attributes}
        {...listeners}
      >
        {/* Six-dot grip icon */}
        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
          <circle cx="5" cy="3" r="1.5" />
          <circle cx="11" cy="3" r="1.5" />
          <circle cx="5" cy="8" r="1.5" />
          <circle cx="11" cy="8" r="1.5" />
          <circle cx="5" cy="13" r="1.5" />
          <circle cx="11" cy="13" r="1.5" />
        </svg>
      </button>
      <input
        type="checkbox"
        checked={!!task.completedOn}
        onChange={() => onToggle(task)}
        aria-label={`Mark "${task.title}" as ${task.completedOn ? "incomplete" : "complete"}`}
        className="mt-1 h-4 w-4 text-cyan-700 border-gray-300 rounded focus:ring-cyan-500"
      />
      <div className="flex-1">
        <p
          className={`font-medium ${
            task.completedOn ? "line-through text-gray-400" : "text-black"
          }`}
        >
          {task.title}
        </p>
        {task.description && (
          <p
            className={`text-sm mt-1 ${
              task.completedOn ? "line-through text-gray-400" : "text-gray-600"
            }`}
          >
            {task.description}
          </p>
        )}
      </div>
      <button
        onClick={() => onDelete(task)}
        aria-label={`Delete "${task.title}"`}
        className="text-red-600 hover:text-red-700 text-sm font-medium bg-transparent border-0 cursor-pointer"
      >
        Delete
      </button>
    </li>
  );
};

const Tasks = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [tasks, setTasks] = useState<Task[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

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

  const loadTasks = useCallback(async () => {
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/tasks`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) {
        throw new Error("Failed to load tasks.");
      }
      const data: TaskCollection = await res.json();
      setTasks(data.member ?? data["hydra:member"] ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load tasks.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isAuthenticated) {
      loadTasks();
    }
  }, [isAuthenticated, loadTasks]);

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
      await loadTasks();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create task.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleToggle = async (task: Task) => {
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({
          completedOn: task.completedOn ? null : new Date().toISOString(),
        }),
      });
      if (!res.ok) {
        throw new Error("Failed to update task.");
      }
      await loadTasks();
    } catch (err) {
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
      await loadTasks();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete task.");
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
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <p className="text-gray-500">Loading...</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Tasks - Aura</title>
      </Head>
      <div className="min-h-screen bg-gray-50 px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <h1 className="text-2xl font-bold text-black mb-6">Tasks</h1>

          <form
            onSubmit={handleCreate}
            className="bg-white rounded-lg shadow-card p-6 mb-6 space-y-4"
          >
            <div>
              <label htmlFor="title" className="block text-sm font-medium text-gray-700">
                Title
              </label>
              <input
                id="title"
                type="text"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                required
                maxLength={255}
                className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-500"
              />
            </div>
            <div>
              <label htmlFor="description" className="block text-sm font-medium text-gray-700">
                Description <span className="text-gray-400">(optional)</span>
              </label>
              <textarea
                id="description"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                rows={3}
                className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-500"
              />
            </div>
            <button
              type="submit"
              disabled={isSubmitting || !title.trim()}
              className="bg-cyan-700 text-white py-2 px-4 rounded-md font-semibold hover:bg-cyan-800 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isSubmitting ? "Adding..." : "Add Task"}
            </button>
          </form>

          {error && (
            <div
              role="alert"
              className="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded"
            >
              {error}
            </div>
          )}

          {isLoading ? (
            <p className="text-gray-500">Loading tasks...</p>
          ) : tasks.length === 0 ? (
            <p className="text-gray-500 bg-white rounded-lg shadow-card p-6">
              No tasks yet. Add one above to get started.
            </p>
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
                      onToggle={handleToggle}
                      onDelete={handleDelete}
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
