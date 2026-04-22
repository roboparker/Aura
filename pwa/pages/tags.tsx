import Head from "next/head";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import { ENTRYPOINT } from "../config/entrypoint";

interface Tag {
  "@id": string;
  id: number;
  title: string;
  description: string | null;
  color: string;
}

interface TagCollection {
  member?: Tag[];
  "hydra:member"?: Tag[];
}

const DEFAULT_COLOR = "#6b7280";

const Tags = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [tags, setTags] = useState<Tag[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [color, setColor] = useState(DEFAULT_COLOR);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editTitle, setEditTitle] = useState("");
  const [editDescription, setEditDescription] = useState("");
  const [editColor, setEditColor] = useState(DEFAULT_COLOR);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.push("/signin");
    }
  }, [authLoading, isAuthenticated, router]);

  const loadTags = useCallback(async () => {
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/tags`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) {
        throw new Error("Failed to load tags.");
      }
      const data: TagCollection = await res.json();
      setTags(data.member ?? data["hydra:member"] ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load tags.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isAuthenticated) {
      loadTags();
    }
  }, [isAuthenticated, loadTags]);

  const handleCreate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!title.trim()) return;

    setIsSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/tags`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          title: title.trim(),
          description: description.trim() || null,
          color,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to create tag.",
        );
      }
      setTitle("");
      setDescription("");
      setColor(DEFAULT_COLOR);
      await loadTags();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create tag.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const startEdit = (tag: Tag) => {
    setEditingId(tag["@id"]);
    setEditTitle(tag.title);
    setEditDescription(tag.description ?? "");
    setEditColor(tag.color);
  };

  const cancelEdit = () => {
    setEditingId(null);
  };

  const handleUpdate = async (event: FormEvent<HTMLFormElement>, tag: Tag) => {
    event.preventDefault();
    if (!editTitle.trim()) return;

    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${tag["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({
          title: editTitle.trim(),
          description: editDescription.trim() || null,
          color: editColor,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to update tag.",
        );
      }
      setEditingId(null);
      await loadTags();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update tag.");
    }
  };

  const handleDelete = async (tag: Tag) => {
    if (!window.confirm(`Delete tag "${tag.title}"? It will be removed from any tasks using it.`)) {
      return;
    }

    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${tag["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) {
        throw new Error("Failed to delete tag.");
      }
      await loadTags();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete tag.");
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
        <title>Tags - Aura</title>
      </Head>
      <div className="min-h-screen bg-gray-50 px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <h1 className="text-2xl font-bold text-black mb-6">Tags</h1>

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
                maxLength={100}
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
                rows={2}
                className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-500"
              />
            </div>
            <div>
              <label htmlFor="color" className="block text-sm font-medium text-gray-700">
                Color
              </label>
              <div className="mt-1 flex items-center gap-3">
                <input
                  id="color"
                  type="color"
                  value={color}
                  onChange={(e) => setColor(e.target.value)}
                  className="h-10 w-16 border border-gray-300 rounded-md cursor-pointer"
                />
                <span className="text-sm font-mono text-gray-600">{color}</span>
              </div>
            </div>
            <button
              type="submit"
              disabled={isSubmitting || !title.trim()}
              className="bg-cyan-700 text-white py-2 px-4 rounded-md font-semibold hover:bg-cyan-800 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isSubmitting ? "Adding..." : "Add Tag"}
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
            <p className="text-gray-500">Loading tags...</p>
          ) : tags.length === 0 ? (
            <p className="text-gray-500 bg-white rounded-lg shadow-card p-6">
              No tags yet. Create one above to start organizing your tasks.
            </p>
          ) : (
            <ul className="space-y-2" data-testid="tag-list">
              {tags.map((tag) => (
                <li
                  key={tag["@id"]}
                  className="bg-white rounded-lg shadow-card p-4"
                  data-testid="tag-item"
                >
                  {editingId === tag["@id"] ? (
                    <form onSubmit={(e) => handleUpdate(e, tag)} className="space-y-3">
                      <input
                        type="text"
                        value={editTitle}
                        onChange={(e) => setEditTitle(e.target.value)}
                        required
                        maxLength={100}
                        aria-label="Title"
                        className="block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-500"
                      />
                      <textarea
                        value={editDescription}
                        onChange={(e) => setEditDescription(e.target.value)}
                        rows={2}
                        aria-label="Description"
                        className="block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-500"
                      />
                      <div className="flex items-center gap-3">
                        <input
                          type="color"
                          value={editColor}
                          onChange={(e) => setEditColor(e.target.value)}
                          aria-label="Color"
                          className="h-10 w-16 border border-gray-300 rounded-md cursor-pointer"
                        />
                        <span className="text-sm font-mono text-gray-600">{editColor}</span>
                      </div>
                      <div className="flex gap-2">
                        <button
                          type="submit"
                          className="bg-cyan-700 text-white py-1 px-3 rounded-md text-sm font-semibold hover:bg-cyan-800"
                        >
                          Save
                        </button>
                        <button
                          type="button"
                          onClick={cancelEdit}
                          className="bg-gray-200 text-gray-700 py-1 px-3 rounded-md text-sm hover:bg-gray-300"
                        >
                          Cancel
                        </button>
                      </div>
                    </form>
                  ) : (
                    <div className="flex items-start gap-3">
                      <span
                        className="inline-block mt-1 px-2 py-0.5 rounded text-xs font-semibold text-white"
                        style={{ backgroundColor: tag.color }}
                      >
                        {tag.title}
                      </span>
                      <div className="flex-1">
                        {tag.description && (
                          <p className="text-sm text-gray-600">{tag.description}</p>
                        )}
                      </div>
                      <button
                        onClick={() => startEdit(tag)}
                        className="text-cyan-700 hover:text-cyan-900 text-sm font-medium bg-transparent border-0 cursor-pointer"
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => handleDelete(tag)}
                        aria-label={`Delete "${tag.title}"`}
                        className="text-red-600 hover:text-red-700 text-sm font-medium bg-transparent border-0 cursor-pointer"
                      >
                        Delete
                      </button>
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </>
  );
};

export default Tags;
