/**
 * Helpers for surfacing API Platform validation errors (HTTP 422) as
 * per-field inline messages.
 *
 * API Platform returns a `ConstraintViolationList` on 422 with a
 * `violations` array of `{ propertyPath, message }` pairs (the JSON-LD
 * variant also carries `hydra:description`). The PWA historically only
 * showed the flat `detail`/`hydra:description` string; this maps the
 * structured list to a `propertyPath -> message` dictionary so individual
 * inputs can render their own error text.
 *
 * For custom field values the server emits paths shaped like
 * `customFieldValues[0].value` (scalar) or `customFieldValues[0].value.amount`
 * (money sub-field) — build those keys with {@link cfvPath}.
 */
export type ViolationMap = Record<string, string>;

interface RawViolation {
  propertyPath?: unknown;
  message?: unknown;
}

/**
 * Parse a fetch `Response` body into a `propertyPath -> message` map. Safe
 * to call on any response — returns `{}` when the body has no violations
 * (non-422, malformed JSON, etc.). The first message wins for a repeated
 * path, matching how a single input renders one error.
 */
export const parseViolations = async (res: Response): Promise<ViolationMap> => {
  const data: unknown = await res.json().catch(() => null);
  return violationsFromBody(data);
};

/**
 * Synchronous variant for callers that already hold the parsed body.
 */
export const violationsFromBody = (data: unknown): ViolationMap => {
  if (data === null || typeof data !== "object") return {};
  const record = data as Record<string, unknown>;
  const list = record.violations ?? record["hydra:violations"];
  if (!Array.isArray(list)) return {};

  const map: ViolationMap = {};
  for (const entry of list) {
    if (entry === null || typeof entry !== "object") continue;
    const { propertyPath, message } = entry as RawViolation;
    if (typeof propertyPath !== "string" || typeof message !== "string") {
      continue;
    }
    if (!(propertyPath in map)) {
      map[propertyPath] = message;
    }
  }
  return map;
};

/**
 * Build the property path the server uses for a custom field value at a
 * given index in the `customFieldValues` write array. Pass a `suffix` for
 * nested sub-fields, e.g. `cfvPath(0, ".amount")` →
 * `customFieldValues[0].value.amount`.
 */
export const cfvPath = (index: number, suffix = ""): string =>
  `customFieldValues[${index}].value${suffix}`;

/**
 * First message matching `path` exactly, or any message whose path starts
 * with `path` (so a `.amount` / `[2]` sub-violation still surfaces on the
 * field row). Returns `null` when nothing matches.
 */
export const violationFor = (
  violations: ViolationMap,
  path: string,
): string | null => {
  if (violations[path]) return violations[path];
  for (const [key, message] of Object.entries(violations)) {
    if (key.startsWith(path)) return message;
  }
  return null;
};
