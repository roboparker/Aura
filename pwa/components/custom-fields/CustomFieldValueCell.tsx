import type { ReactNode } from "react";
import { currencyFractionDigits } from "@/lib/currencies";
import { cn } from "@/lib/utils";
import type { CustomFieldDefinition } from "./types";

/**
 * Compact, read-only renderer for one custom-field value in a task-list
 * column (#227 tasks redesign). Mirrors the value encodings written by
 * `value-editors.tsx`:
 *   boolean -> bool · text/url -> string · numeric -> number
 *   money   -> {amount:int-minor, currency} · date/time/datetime -> string
 *   select  -> option key (or key[]) · reference -> IRI (or IRI[])
 */
interface Props {
  definition: CustomFieldDefinition;
  value: unknown;
  /** Resolve a reference IRI to a display label (e.g. user name). */
  resolveRef?: (iri: string) => string | null;
  className?: string;
}

const dateFmt = new Intl.DateTimeFormat(undefined, {
  month: "short",
  day: "numeric",
});

const isEmpty = (v: unknown): boolean =>
  v === null ||
  v === undefined ||
  v === "" ||
  (Array.isArray(v) && v.length === 0);

const asStringArray = (v: unknown): string[] =>
  Array.isArray(v) ? v.filter((x): x is string => typeof x === "string") : [];

const CustomFieldValueCell = ({
  definition,
  value,
  resolveRef,
  className,
}: Props) => {
  if (isEmpty(value)) {
    return <span className={cn("text-muted-foreground/50", className)}>—</span>;
  }

  const wrap = (node: ReactNode) => (
    <span className={cn("text-sm", className)}>{node}</span>
  );

  switch (definition.kind) {
    case "boolean":
      return wrap(value === true ? "Yes" : "No");

    case "text":
      return wrap(<span className="truncate">{String(value)}</span>);

    case "numeric": {
      if (definition.subtype === "money" && isMoney(value)) {
        const digits = currencyFractionDigits(value.currency);
        const major = value.amount / 10 ** digits;
        return wrap(
          new Intl.NumberFormat(undefined, {
            style: "currency",
            currency: value.currency,
          }).format(major),
        );
      }
      return wrap(
        typeof value === "number"
          ? new Intl.NumberFormat().format(value)
          : String(value),
      );
    }

    case "date": {
      const str = String(value);
      if (definition.subtype === "time") return wrap(str.slice(0, 5));
      const d = new Date(str);
      return wrap(Number.isNaN(d.getTime()) ? str : dateFmt.format(d));
    }

    case "select": {
      const options = definition.config.options ?? [];
      const keys =
        definition.subtype === "multi" ? asStringArray(value) : [String(value)];
      return (
        <span className={cn("flex flex-wrap items-center gap-1", className)}>
          {keys.map((key) => {
            const opt = options.find((o) => o.key === key);
            return (
              <span
                key={key}
                className="inline-flex items-center gap-1 text-sm"
              >
                {opt?.color && (
                  <span
                    className="h-2 w-2 shrink-0 rounded-full"
                    style={{ backgroundColor: opt.color }}
                  />
                )}
                {opt?.label ?? key}
              </span>
            );
          })}
        </span>
      );
    }

    case "reference": {
      const iris = Array.isArray(value) ? asStringArray(value) : [String(value)];
      const labels = iris.map(
        (iri) => resolveRef?.(iri) ?? iri.split("/").pop() ?? iri,
      );
      return wrap(labels.join(", "));
    }

    default:
      return wrap(String(value));
  }
};

interface Money {
  amount: number;
  currency: string;
}
const isMoney = (v: unknown): v is Money =>
  typeof v === "object" &&
  v !== null &&
  typeof (v as Money).amount === "number" &&
  typeof (v as Money).currency === "string";

export default CustomFieldValueCell;
