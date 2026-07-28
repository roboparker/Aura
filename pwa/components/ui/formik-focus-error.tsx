import { useEffect, useRef } from "react";
import { useFormikContext } from "formik";

/** Marks the element a failed submit should focus, in preference to a field. */
export const ERROR_SUMMARY_ATTR = "data-error-summary";

/**
 * Opt-in marker for controls that can't be found by `name`.
 *
 * Design-system controls (Radix Checkbox, Switch, …) render a visible button
 * plus a *hidden* proxy input that carries the `name` for form submission.
 * Resolving by name alone therefore finds the hidden one and focus lands
 * somewhere invisible — worse than not moving it at all. Put this attribute on
 * the visible control instead.
 */
export const FIELD_NAME_ATTR = "data-field-name";

const NAMED_CONTROL = "input,select,textarea,[contenteditable='true']";

/** Focusable and actually perceivable — excludes Radix's hidden proxy inputs. */
function isVisibleControl(el: HTMLElement): boolean {
  if (el.getAttribute("aria-hidden") === "true") return false;
  if (el.getAttribute("tabindex") === "-1") return false;
  const rect = el.getBoundingClientRect();
  return rect.width > 0 && rect.height > 0;
}

/** Sorts field names into the order their controls appear on screen. */
function orderedByDom(form: HTMLElement, names: string[]): string[] {
  return [...names].sort((a, b) => {
    const ea = findFieldControl(form, a);
    const eb = findFieldControl(form, b);
    if (!ea || !eb) return 0;
    // DOCUMENT_POSITION_FOLLOWING = b comes after a.
    return ea.compareDocumentPosition(eb) & Node.DOCUMENT_POSITION_FOLLOWING
      ? -1
      : 1;
  });
}

/**
 * Resolves a Formik field name to the control the user should be focused on.
 * Exported so an error summary and the focus-on-submit behaviour can't drift.
 */
export function findFieldControl(
  form: HTMLElement,
  name: string,
): HTMLElement | null {
  const tagged = form.querySelector<HTMLElement>(
    `[${FIELD_NAME_ATTR}="${CSS.escape(name)}"]`,
  );
  if (tagged && isVisibleControl(tagged)) return tagged;

  for (const el of form.querySelectorAll<HTMLElement>(NAMED_CONTROL)) {
    if (el.getAttribute("name") === name && isVisibleControl(el)) return el;
  }
  return null;
}

/**
 * Moves focus after a failed submit.
 *
 * Without this, submitting an invalid form leaves focus on `<body>`: the
 * errors appear but the caret is nowhere near them, so a keyboard user re-tabs
 * the whole form and a screen-reader user gets no announcement at all.
 *
 * Two targets, in order of preference:
 *
 * 1. An error **summary** (any element carrying `data-error-summary`). When a
 *    form has one, that's the better landing spot than the first bad field —
 *    the user hears how many problems there are before being dropped into one
 *    of them. This is the GOV.UK error-summary pattern.
 * 2. Otherwise the first invalid control **in DOM order** — not in `errors`
 *    key order, which follows the validator and can differ from what the user
 *    sees.
 *
 * Runs only on a *new* failed submit (tracked by `submitCount`) and only once
 * the attempt has settled, so it can't fight Formik's re-render or steal focus
 * while the user is typing a correction.
 */
export function FormikFocusError() {
  const { submitCount, isSubmitting, errors } = useFormikContext<
    Record<string, unknown>
  >();
  const anchorRef = useRef<HTMLSpanElement>(null);
  const handledSubmit = useRef(0);

  useEffect(() => {
    if (submitCount === 0 || submitCount === handledSubmit.current) return;
    // Wait for the submit attempt to settle; focusing mid-flight would be
    // undone by the re-render that follows.
    if (isSubmitting) return;

    const names = Object.keys(errors);
    if (names.length === 0) {
      // A clean submit still counts as handled, so a later failure re-fires.
      handledSubmit.current = submitCount;
      return;
    }
    handledSubmit.current = submitCount;

    const form = anchorRef.current?.closest("form");
    if (!form) return;

    const summary = form.querySelector<HTMLElement>(`[${ERROR_SUMMARY_ATTR}]`);
    if (summary) {
      summary.focus();
      return;
    }

    // First invalid control in DOM order — not in `errors` key order, which
    // follows the validator and can differ from what the user sees.
    for (const name of orderedByDom(form, names)) {
      const target = findFieldControl(form, name);
      if (target) {
        target.focus();
        return;
      }
    }
  }, [submitCount, isSubmitting, errors]);

  return <span ref={anchorRef} aria-hidden="true" className="hidden" />;
}
