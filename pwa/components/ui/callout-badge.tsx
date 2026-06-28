import * as React from "react";

import { cn } from "@/lib/utils";

/**
 * Tonal variants for {@link CalloutBadge}. The same palette the rest of
 * the app uses semantically — pick the one that matches the message
 * the badge is announcing (error → destructive, success → primary,
 * informational → muted). Adding tones? Mirror the bg / border / text
 * / glow shape below.
 *
 * Glow is stacked as two box-shadows so the effect reads as "the
 * border is glowing" rather than "the whole box is bleeding light":
 *
 *  1. A tight 1px halo at the border edge (no blur, +1px spread) —
 *     locks the glow onto the rectangle outline.
 *  2. A modest 14px soft outer glow (no spread) — falls off quickly
 *     so the corners don't round into a hazy circle.
 *
 * Both shadow layers go through `color-mix(... transparent)` so the
 * intensity is independent of the CSS var's own opacity (the tokens
 * we use today are fully opaque oklch / hsl values).
 */
type Tone = "destructive" | "primary" | "muted";

interface ToneStyle {
  border: string;
  text: string;
  /**
   * Style block carries both the OPAQUE tinted background and the
   * outer-glow box-shadows. The bg has to be opaque (not
   * `bg-destructive/10`) so the box itself occludes the shadow
   * underneath — otherwise the glow bleeds through the translucent fill
   * and reads as a halo inside the box. We mix the tone color with the
   * card background via `color-mix` so the result still feels like a
   * subtle tint, just not see-through.
   */
  style?: React.CSSProperties;
}

const TONE: Record<Tone, ToneStyle> = {
  destructive: {
    border: "border-destructive/50",
    text: "text-destructive",
    style: {
      backgroundColor:
        "color-mix(in oklab, var(--color-destructive) 10%, var(--color-card))",
      boxShadow: [
        "0 0 0 1px color-mix(in oklab, var(--color-destructive) 55%, transparent)",
        "0 0 14px 0 color-mix(in oklab, var(--color-destructive) 35%, transparent)",
      ].join(", "),
    },
  },
  primary: {
    border: "border-primary/50",
    text: "text-primary",
    style: {
      backgroundColor:
        "color-mix(in oklab, var(--color-primary) 10%, var(--color-card))",
      boxShadow: [
        "0 0 0 1px color-mix(in oklab, var(--color-primary) 55%, transparent)",
        "0 0 14px 0 color-mix(in oklab, var(--color-primary) 35%, transparent)",
      ].join(", "),
    },
  },
  muted: {
    border: "border-border",
    text: "text-muted-foreground",
    style: {
      backgroundColor:
        "color-mix(in oklab, var(--color-muted) 40%, var(--color-card))",
    },
  },
};

interface CalloutBadgeProps extends React.ComponentProps<"div"> {
  /**
   * Color/intensity preset. `destructive` and `primary` carry a soft
   * outer glow in their own hue; `muted` stays understated for neutral
   * informational callouts.
   */
  tone?: Tone;
  /**
   * `icon` collapses padding so a lucide icon (or any compact glyph)
   * sits centered in the square. `text` widens slightly and shrinks
   * the font for short uppercase labels like "USED" or "NEW".
   */
  shape?: "icon" | "text";
}

/**
 * Square badge used as the headline visual for status / callout cards
 * (the "this invite has expired" splash, "your changes are saved"
 * toasts, etc.). Wraps an icon or a short text label in a tinted box
 * with a coloured border + matching outer glow.
 *
 * The badge itself is decorative — set `aria-hidden` on the wrapper
 * (default) and put the meaningful copy in the card body so screen
 * readers don't read a duplicate "clock icon" before the
 * "this invite has expired" heading.
 */
const CalloutBadge = ({
  tone = "muted",
  shape = "icon",
  className,
  style,
  children,
  ...props
}: CalloutBadgeProps) => {
  const t = TONE[tone];
  return (
    <div
      role="presentation"
      aria-hidden
      className={cn(
        "mx-auto flex items-center justify-center rounded-md border",
        shape === "icon" ? "h-12 w-12" : "h-10 px-3 text-xs font-semibold uppercase tracking-wider",
        t.border,
        t.text,
        className,
      )}
      style={{ ...t.style, ...style }}
      {...props}
    >
      {children}
    </div>
  );
};

export { CalloutBadge, type CalloutBadgeProps };
