import {
  ClipboardEvent,
  KeyboardEvent,
  useCallback,
  useId,
  useState,
} from "react";
import { X } from "lucide-react";
import { deterministicPaletteColor } from "@/lib/avatarPalette";
import { cn } from "@/lib/utils";

/**
 * Reusable multi-email chip input. Backed by an external `value`
 * array, so the caller owns state and can submit the list directly.
 *
 * Interactions:
 *   - Enter, comma, or Tab on a non-empty input → push that string
 *     as a chip and clear the input
 *   - Backspace on an empty input → remove the last chip
 *   - Paste of a comma/whitespace-separated list → split into chips
 *   - Invalid email → input stays focused and a thin red ring appears
 *     until the user fixes or deletes; never blocks the form, only
 *     refuses to chip the bad value
 *
 * Chips show a deterministic colored letter tile keyed off the email —
 * good-enough visual identity for unrecognised emails without an
 * extra `/users` lookup.
 */
interface Props {
  value: string[];
  onChange: (next: string[]) => void;
  placeholder?: string;
  /** Optional html id for the input — pair with a `<Label htmlFor>`
   *  outside the component. */
  inputId?: string;
  /** Optional cap; further chips are silently ignored. */
  maxItems?: number;
  /** Optional className applied to the outer container. */
  className?: string;
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const isValidEmail = (s: string) => EMAIL_RE.test(s);

const EmailChipInput = ({
  value,
  onChange,
  placeholder = "Type an email…",
  inputId,
  maxItems,
  className,
}: Props) => {
  const autoId = useId();
  const id = inputId ?? autoId;
  const [draft, setDraft] = useState("");
  const [hasError, setHasError] = useState(false);

  const tryAdd = useCallback(
    (raw: string): boolean => {
      const candidates = raw
        .split(/[\s,]+/)
        .map((s) => s.trim().toLowerCase())
        .filter(Boolean);
      if (candidates.length === 0) return true;

      const next = [...value];
      const seen = new Set(next);
      let allValid = true;
      for (const c of candidates) {
        if (!isValidEmail(c)) {
          allValid = false;
          continue;
        }
        if (seen.has(c)) continue;
        if (maxItems !== undefined && next.length >= maxItems) break;
        next.push(c);
        seen.add(c);
      }
      if (next.length !== value.length) onChange(next);
      return allValid;
    },
    [value, onChange, maxItems],
  );

  const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter" || e.key === ",") {
      e.preventDefault();
      if (draft.trim() === "") return;
      const ok = tryAdd(draft);
      if (ok) {
        setDraft("");
        setHasError(false);
      } else {
        setHasError(true);
      }
      return;
    }
    if (e.key === "Tab" && draft.trim() !== "") {
      // Tab only chips when there's something to chip; otherwise it
      // moves focus normally.
      e.preventDefault();
      const ok = tryAdd(draft);
      if (ok) {
        setDraft("");
        setHasError(false);
      } else {
        setHasError(true);
      }
      return;
    }
    if (e.key === "Backspace" && draft === "" && value.length > 0) {
      e.preventDefault();
      onChange(value.slice(0, -1));
    }
  };

  const onPaste = (e: ClipboardEvent<HTMLInputElement>) => {
    const text = e.clipboardData.getData("text");
    if (!/[\s,]/.test(text)) return; // single email — let default paste do its thing
    e.preventDefault();
    tryAdd(text);
  };

  const removeAt = (idx: number) => {
    const next = value.slice();
    next.splice(idx, 1);
    onChange(next);
  };

  return (
    <div
      className={cn(
        "flex flex-wrap items-center gap-1.5 rounded-md border bg-background px-2 py-1.5 text-sm",
        "focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-1 focus-within:ring-offset-background",
        hasError && "ring-1 ring-destructive",
        className,
      )}
      onClick={() => {
        // Click anywhere in the container focuses the input.
        document.getElementById(id)?.focus();
      }}
    >
      {value.map((email, idx) => (
        <span
          key={email}
          className="inline-flex items-center gap-1 rounded-md border bg-muted/40 pl-1 pr-1 py-0.5"
        >
          <span
            aria-hidden
            className="inline-flex items-center justify-center h-5 w-5 rounded text-xs font-semibold text-white"
            style={{ backgroundColor: deterministicPaletteColor(email) }}
          >
            {(email[0] ?? "?").toUpperCase()}
          </span>
          <span className="text-xs">{email}</span>
          <button
            type="button"
            aria-label={`Remove ${email}`}
            onClick={(e) => {
              e.stopPropagation();
              removeAt(idx);
            }}
            className="text-muted-foreground hover:text-foreground"
          >
            <X className="h-3 w-3" />
          </button>
        </span>
      ))}
      <input
        id={id}
        type="email"
        value={draft}
        onChange={(e) => {
          setDraft(e.target.value);
          if (hasError) setHasError(false);
        }}
        onKeyDown={onKeyDown}
        onPaste={onPaste}
        placeholder={value.length === 0 ? placeholder : ""}
        className="flex-1 min-w-[10ch] bg-transparent outline-none text-sm"
      />
    </div>
  );
};

export default EmailChipInput;
