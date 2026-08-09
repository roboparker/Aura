import Link from "next/link";
import { useEffect, useState } from "react";
import { Trash2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { formatPurgeDate, remainingLabel } from "@/lib/deletionTypes";

interface DeletedRecord {
  id: string;
  name: string;
  deletedAt?: string | null;
  purgeAfter?: string | null;
  blockedByOrganization?: boolean;
}

interface Props {
  /** `/spaces/deleted` or `/organizations/deleted`. */
  endpoint: string;
  /** Key the array sits under in the response. */
  collectionKey: "spaces" | "organizations";
  /** Builds the settings link where the restore banner lives. */
  hrefFor: (id: string) => string;
  noun: string;
}

/**
 * "Recently deleted" — the in-app route back to something inside its grace
 * period.
 *
 * It exists because deletion removes the thing from every normal listing, which
 * would otherwise leave the emailed link as the *only* way back. That's fine
 * until someone deletes the wrong space and can't find the email.
 *
 * Renders nothing at all when the list is empty: a permanent empty
 * "Recently deleted" heading on every spaces page would be clutter that
 * suggests deletion is a routine occurrence.
 */
const RecentlyDeletedList = ({ endpoint, collectionKey, hrefFor, noun }: Props) => {
  const [records, setRecords] = useState<DeletedRecord[]>([]);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const res = await fetch(`${ENTRYPOINT}${endpoint}`, {
          credentials: "include",
          headers: { Accept: "application/json" },
        });
        if (!res.ok) return;
        const data = await res.json();
        const list = data[collectionKey];
        if (!cancelled && Array.isArray(list)) setRecords(list);
      } catch {
        /* best-effort: this is a recovery aid, not core navigation */
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [endpoint, collectionKey]);

  if (records.length === 0) return null;

  return (
    <section className="mt-8">
      <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
        <Trash2 className="h-4 w-4" aria-hidden />
        Recently deleted
      </h2>
      <ul className="mt-2 space-y-2">
        {records.map((record) => (
          <li
            key={record.id}
            className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-500/40 bg-amber-500/5 px-3 py-2"
          >
            <div className="min-w-0">
              <p className="truncate text-sm font-medium">{record.name}</p>
              <p className="text-xs text-muted-foreground">
                {remainingLabel(record.purgeAfter)}
                {record.purgeAfter
                  ? ` — permanently deleted on ${formatPurgeDate(record.purgeAfter)}`
                  : ""}
              </p>
            </div>
            {record.blockedByOrganization ? (
              <span className="text-xs text-muted-foreground">
                Restore its organization instead
              </span>
            ) : (
              <Link
                href={hrefFor(record.id)}
                className="text-sm text-primary hover:underline"
              >
                Restore {noun}
              </Link>
            )}
          </li>
        ))}
      </ul>
    </section>
  );
};

export default RecentlyDeletedList;
