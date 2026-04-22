import Head from "next/head";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import { ENTRYPOINT } from "../config/entrypoint";

interface Todo {
  "@id": string;
  id: number;
  title: string;
  description: string | null;
  createdOn: string;
  completedOn: string | null;
}

interface HydraCollection {
  "hydra:member": Todo[];
  "hydra:totalItems"?: number;
}

const Todos = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [todos, setTodos] = useState<Todo[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.push("/signin");
    }
  }, [authLoading, isAuthenticated, router]);

  const loadTodos = useCallback(async () => {
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/todos`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) {
        throw new Error("Failed to load todos.");
      }
      const data: HydraCollection = await res.json();
      setTodos(data["hydra:member"] ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load todos.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isAuthenticated) {
      loadTodos();
    }
  }, [isAuthenticated, loadTodos]);

  const handleCreate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!title.trim()) return;

    setIsSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/todos`, {
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
          data["hydra:description"] || data.detail || "Failed to create todo.",
        );
      }
      setTitle("");
      setDescription("");
      await loadTodos();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create todo.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleToggle = async (todo: Todo) => {
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${todo["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({
          completedOn: todo.completedOn ? null : new Date().toISOString(),
        }),
      });
      if (!res.ok) {
        throw new Error("Failed to update todo.");
      }
      await loadTodos();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update todo.");
    }
  };

  const handleDelete = async (todo: Todo) => {
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${todo["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) {
        throw new Error("Failed to delete todo.");
      }
      await loadTodos();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete todo.");
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
        <title>Todos - Aura</title>
      </Head>
      <div className="min-h-screen bg-gray-50 px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <h1 className="text-2xl font-bold text-black mb-6">Todos</h1>

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
              {isSubmitting ? "Adding..." : "Add Todo"}
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
            <p className="text-gray-500">Loading todos...</p>
          ) : todos.length === 0 ? (
            <p className="text-gray-500 bg-white rounded-lg shadow-card p-6">
              No todos yet. Add one above to get started.
            </p>
          ) : (
            <ul className="space-y-2" data-testid="todo-list">
              {todos.map((todo) => (
                <li
                  key={todo["@id"]}
                  className="bg-white rounded-lg shadow-card p-4 flex items-start gap-3"
                  data-testid="todo-item"
                >
                  <input
                    type="checkbox"
                    checked={!!todo.completedOn}
                    onChange={() => handleToggle(todo)}
                    aria-label={`Mark "${todo.title}" as ${todo.completedOn ? "incomplete" : "complete"}`}
                    className="mt-1 h-4 w-4 text-cyan-700 border-gray-300 rounded focus:ring-cyan-500"
                  />
                  <div className="flex-1">
                    <p
                      className={`font-medium ${
                        todo.completedOn ? "line-through text-gray-400" : "text-black"
                      }`}
                    >
                      {todo.title}
                    </p>
                    {todo.description && (
                      <p
                        className={`text-sm mt-1 ${
                          todo.completedOn ? "line-through text-gray-400" : "text-gray-600"
                        }`}
                      >
                        {todo.description}
                      </p>
                    )}
                  </div>
                  <button
                    onClick={() => handleDelete(todo)}
                    aria-label={`Delete "${todo.title}"`}
                    className="text-red-600 hover:text-red-700 text-sm font-medium bg-transparent border-0 cursor-pointer"
                  >
                    Delete
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </>
  );
};

export default Todos;
