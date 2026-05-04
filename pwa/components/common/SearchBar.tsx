import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/router";
import { Search } from "lucide-react";
import { Input } from "@/components/ui/input";

/**
 * Always-visible search input in the navbar. Submitting (Enter) sends the
 * user to /tasks?search=… so the existing Tasks listing handles the
 * fetch — no separate results page yet (#87 follow-up). Ctrl/Cmd+K
 * focuses the input from anywhere in the app.
 *
 * The local draft state mirrors the URL while the user types so the
 * field doesn't fight with browser autocomplete; the input commits to
 * the URL on submit, and pre-fills from the URL when the user lands
 * back on /tasks via a deep link.
 */
const SearchBar = () => {
  const router = useRouter();
  const inputRef = useRef<HTMLInputElement>(null);
  const initial = typeof router.query.search === "string"
    ? router.query.search
    : "";
  const [draft, setDraft] = useState(initial);

  // Re-sync the draft when the URL changes (e.g. user clears the URL
  // bar or another link navigates with a different ?search). Avoids
  // stale text lingering in the navbar.
  useEffect(() => {
    setDraft(initial);
  }, [initial]);

  // Global focus shortcut — match the convention used by GitHub, Linear,
  // and most app shells. Ignore the binding while focus is in another
  // editable element so we don't steal the user's typing.
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      const isMeta = e.metaKey || e.ctrlKey;
      if (!isMeta || e.key.toLowerCase() !== "k") return;
      e.preventDefault();
      inputRef.current?.focus();
      inputRef.current?.select();
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, []);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const trimmed = draft.trim();
    const next = trimmed.length > 0
      ? { pathname: "/tasks", query: { search: trimmed } }
      : { pathname: "/tasks" };
    void router.push(next);
  };

  return (
    <form
      onSubmit={submit}
      role="search"
      className="hidden sm:block"
      aria-label="Search tasks"
    >
      <div className="relative">
        <Search
          className="absolute left-2 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"
          aria-hidden="true"
        />
        <Input
          ref={inputRef}
          type="search"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          placeholder="Search tasks…  ⌘K"
          className="h-8 w-56 pl-8"
          data-testid="navbar-search"
          aria-label="Search tasks"
        />
      </div>
    </form>
  );
};

export default SearchBar;
