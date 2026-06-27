import { useEffect, useState } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import UserAvatar from "@/components/user/UserAvatar";
import { displayName } from "@/lib/userDisplay";
import type {
  ActivityActor,
  ActivityResponse,
  ActivityRow,
} from "./types";

/**
 * Right-side drawer showing the project-scoped custom-fields change log,
 * read from `GET /projects/{id}/custom_field_definitions/activity`. Each
 * row pairs the Gedmo audit entry with an actor chip resolved from the
 * response's actor map (no per-row user fetch).
 */
interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Null on the space-level manager (no project route → no change log). */
  projectId: string | null;
}

const ACTION_VERB: Record<string, string> = {
  create: "created",
  update: "updated",
  remove: "removed",
};

const FIELD_LABELS: Record<string, string> = {
  name: "name",
  kind: "kind",
  subtype: "subtype",
  config: "configuration",
  footer: "footer",
  nullable: "required",
  position: "order",
};

const CustomFieldChangeLog = ({ open, onOpenChange, projectId }: Props) => {
  const [items, setItems] = useState<ActivityRow[]>([]);
  const [actors, setActors] = useState<Record<string, ActivityActor>>({});
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open || null === projectId) return;
    let cancelled = false;
    setIsLoading(true);
    setError(null);
    void (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/projects/${projectId}/custom_field_definitions/activity`,
          { credentials: "include", headers: { Accept: "application/json" } },
        );
        if (!res.ok) throw new Error("Failed to load the change log.");
        const data: ActivityResponse = await res.json();
        if (cancelled) return;
        setItems(data.items);
        setActors(data.actors);
      } catch (err) {
        if (!cancelled) {
          setError(
            err instanceof Error ? err.message : "Failed to load the change log.",
          );
        }
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [open, projectId]);

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side="right"
        className="flex w-full flex-col gap-0 p-0 sm:max-w-md"
        data-testid="custom-fields-changelog-sheet"
      >
        <SheetHeader className="border-b px-5 py-4">
          <SheetTitle>Change log</SheetTitle>
          <SheetDescription>
            Every create, edit, and delete on this project&apos;s field schema.
          </SheetDescription>
        </SheetHeader>

        <div className="flex-1 overflow-y-auto px-5 py-4">
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : error ? (
            <p className="text-sm text-destructive">{error}</p>
          ) : items.length === 0 ? (
            <p className="text-sm text-muted-foreground">No changes yet.</p>
          ) : (
            <ol className="space-y-4">
              {items.map((row) => (
                <ChangeRow
                  key={row.id}
                  row={row}
                  actor={row.actor ? actors[row.actor] : undefined}
                />
              ))}
            </ol>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
};

const ChangeRow = ({
  row,
  actor,
}: {
  row: ActivityRow;
  actor?: ActivityActor;
}) => {
  const verb = ACTION_VERB[row.action] ?? row.action;
  const fieldName =
    typeof row.data.name === "string" && row.data.name !== ""
      ? row.data.name
      : "a field";
  const changedKeys = Object.keys(row.data).filter((k) => k in FIELD_LABELS);
  const changed =
    row.action === "update" && changedKeys.length > 0
      ? changedKeys.map((k) => FIELD_LABELS[k]).join(", ")
      : null;

  const avatarUser = actor
    ? {
        givenName: actor.givenName,
        familyName: actor.familyName,
        email: actor.email,
        personalizedColor: actor.personalizedColor ?? "#64748b",
        avatarUrls: (actor.avatarUrls ?? null) as
          | { thumb?: string; profile?: string }
          | null,
      }
    : null;

  return (
    <li className="flex gap-3">
      {avatarUser ? (
        <UserAvatar user={avatarUser} size="sm" />
      ) : (
        <div className="h-8 w-8 shrink-0 rounded-full bg-muted" />
      )}
      <div className="min-w-0 flex-1">
        <p className="text-sm">
          <span className="font-medium">
            {avatarUser ? displayName(avatarUser) : "System"}
          </span>{" "}
          {verb} <span className="font-medium">{fieldName}</span>
          {changed && (
            <span className="text-muted-foreground"> · {changed}</span>
          )}
        </p>
        {row.loggedAt && (
          <p className="text-xs text-muted-foreground">
            {new Date(row.loggedAt).toLocaleString()}
          </p>
        )}
      </div>
    </li>
  );
};

export default CustomFieldChangeLog;
