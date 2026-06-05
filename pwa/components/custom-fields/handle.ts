/**
 * Cosmetic display handle for a custom field, derived from its name
 * (`Brief URL` → `cf-brief-url`). Purely a UI affordance — the API keys
 * fields by UUID, not by handle — so it's safe that two same-named fields
 * would collide. Used in the field table and the editor sheet header.
 */
export const fieldHandle = (name: string): string => {
  const slug = name
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
  return slug ? `cf-${slug}` : "cf-…";
};
