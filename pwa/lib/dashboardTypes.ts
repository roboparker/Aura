/**
 * Wire types for the configurable space dashboard (#759).
 *
 * Mirrors `App\Dashboard\WidgetDefinitionInterface` — keep the two in step when
 * either side changes.
 */

/** One field in a widget's config editor, as described by the server. */
export interface WidgetConfigField {
  key: string;
  type: "text" | "markdown" | "select" | "number" | "boolean";
  label: string;
  placeholder?: string;
  min?: number;
  max?: number;
  options?: { value: string | number; label: string }[];
}

/** A placed widget, as returned by GET /spaces/{id}/dashboard. */
export interface DashboardWidget {
  id: string;
  type: string;
  /** Null when the server doesn't recognise the type — see `available`. */
  name: string | null;
  /**
   * False when no definition is registered for this type: the module that
   * provided it is gone, or this client predates it. The widget still occupies
   * its slot and renders as a placeholder, rather than silently vanishing.
   */
  available: boolean;
  config: Record<string, unknown>;
  configSchema: WidgetConfigField[];
  width: number;
  height: number;
  position: number;
}

export interface DashboardResponse {
  space: string;
  /** Whether this viewer may rearrange the shared layout (space admins). */
  canEdit: boolean;
  widgets: DashboardWidget[];
}

/** A widget type available to add, from GET /spaces/{id}/dashboard/catalog. */
export interface WidgetCatalogEntry {
  type: string;
  name: string;
  description: string;
  group: string;
  defaultSize: { w: number; h: number };
  configSchema: WidgetConfigField[];
}

export const GRID_COLUMNS = 4;

/** Tailwind spans per width. Written out because Tailwind can't see computed class names. */
export const WIDTH_CLASS: Record<number, string> = {
  1: "sm:col-span-1",
  2: "sm:col-span-2",
  3: "sm:col-span-3",
  4: "sm:col-span-4",
};

/** Widget box heights in px, by height unit. */
export const HEIGHT_PX: Record<number, number> = {
  1: 132,
  2: 288,
  3: 420,
  4: 560,
};

/**
 * Seeds a config from a schema so a newly added widget renders immediately
 * instead of opening as a blank card the user has to configure before it does
 * anything. Selects take their first option, numbers their minimum.
 */
export const defaultConfigFor = (
  schema: WidgetConfigField[],
): Record<string, unknown> => {
  const config: Record<string, unknown> = {};
  for (const field of schema) {
    if (field.type === "select" && field.options && field.options.length > 0) {
      config[field.key] = field.options[0].value;
    } else if (field.type === "number") {
      config[field.key] = field.min ?? 5;
    } else if (field.type === "boolean") {
      config[field.key] = false;
    }
    // Text and markdown are left unset — an empty note is a legitimate
    // starting point, and a placeholder reads better than seeded filler.
  }
  return config;
};

/** Groups catalog entries by their `group`, preserving server order within each. */
export const groupCatalog = (
  entries: WidgetCatalogEntry[],
): { group: string; entries: WidgetCatalogEntry[] }[] => {
  const order: string[] = [];
  const byGroup = new Map<string, WidgetCatalogEntry[]>();

  for (const entry of entries) {
    if (!byGroup.has(entry.group)) {
      byGroup.set(entry.group, []);
      order.push(entry.group);
    }
    byGroup.get(entry.group)?.push(entry);
  }

  return order.map((group) => ({ group, entries: byGroup.get(group) ?? [] }));
};
