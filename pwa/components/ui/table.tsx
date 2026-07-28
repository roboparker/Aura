import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * Tracks whether the scroll container actually overflows, and whether it's
 * scrolled to the end. Re-measures on resize of both the container and the
 * table (columns and rows change width as data loads), and on scroll.
 */
function useHorizontalOverflow(ref: React.RefObject<HTMLDivElement | null>) {
  const [state, setState] = React.useState({ scrollable: false, atEnd: true })

  React.useEffect(() => {
    const el = ref.current
    if (!el) return

    const measure = () => {
      const scrollable = el.scrollWidth > el.clientWidth + 1
      const atEnd = el.scrollLeft + el.clientWidth >= el.scrollWidth - 1
      setState((prev) =>
        prev.scrollable === scrollable && prev.atEnd === atEnd
          ? prev
          : { scrollable, atEnd },
      )
    }

    measure()
    const observer = new ResizeObserver(measure)
    observer.observe(el)
    // The table's own width changes independently of the container's — a
    // column appearing is exactly when a previously-fitting table starts to
    // overflow, and the container never resizes for it.
    if (el.firstElementChild) observer.observe(el.firstElementChild)
    el.addEventListener("scroll", measure, { passive: true })

    return () => {
      observer.disconnect()
      el.removeEventListener("scroll", measure)
    }
  }, [ref])

  return state
}

function Table({
  className,
  scrollRegionLabel = "Scrollable table",
  ...props
}: React.ComponentProps<"table"> & {
  /**
   * Names the scroll region for screen readers. Worth overriding when a page
   * has more than one table, so the landmarks are distinguishable.
   */
  scrollRegionLabel?: string
}) {
  const ref = React.useRef<HTMLDivElement>(null)
  const { scrollable, atEnd } = useHorizontalOverflow(ref)

  return (
    <div className="relative">
      <div
        ref={ref}
        data-slot="table-container"
        // Keyboard access is the real defect here, not the missing cue. An
        // `overflow-x-auto` div is scrollable by mouse wheel and touch but is
        // not focusable, so a keyboard-only user cannot scroll it at all — on
        // a phone-width task list that put four columns, including Actions,
        // permanently out of reach (WCAG 2.1.1).
        //
        // Applied only while it actually overflows: an unconditional tab stop
        // on all ~115 tables would be ~115 pointless stops on the ones that
        // fit. A focusable region needs a name, hence role + label.
        {...(scrollable
          ? { tabIndex: 0, role: "region", "aria-label": scrollRegionLabel }
          : {})}
        className={cn(
          "w-full overflow-x-auto",
          scrollable &&
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background",
        )}
      >
        <table
          data-slot="table"
          className={cn("w-full caption-bottom text-sm", className)}
          {...props}
        />
      </div>
      {/* A shadow rather than a colour fade: these tables sit on `bg-card` in
          some places and `bg-background` in others, and a gradient keyed to
          either one shows as a smudge on the other. Hidden once there's
          nothing further right, so it never lies about content that isn't
          there. Decorative — the region label carries the meaning. */}
      {scrollable && !atEnd && (
        <div
          data-scroll-cue=""
          aria-hidden="true"
          className="pointer-events-none absolute inset-y-0 right-0 w-6 bg-gradient-to-l from-black/25 to-transparent dark:from-black/50"
        />
      )}
    </div>
  )
}

function TableHeader({ className, ...props }: React.ComponentProps<"thead">) {
  return (
    <thead
      data-slot="table-header"
      className={cn("[&_tr]:border-b", className)}
      {...props}
    />
  )
}

function TableBody({ className, ...props }: React.ComponentProps<"tbody">) {
  return (
    <tbody
      data-slot="table-body"
      className={cn("[&_tr:last-child]:border-0", className)}
      {...props}
    />
  )
}

function TableFooter({ className, ...props }: React.ComponentProps<"tfoot">) {
  return (
    <tfoot
      data-slot="table-footer"
      className={cn(
        "border-t bg-muted/50 font-medium [&>tr]:last:border-b-0",
        className
      )}
      {...props}
    />
  )
}

function TableRow({ className, ...props }: React.ComponentProps<"tr">) {
  return (
    <tr
      data-slot="table-row"
      className={cn(
        "border-b transition-colors hover:bg-muted/50 has-aria-expanded:bg-muted/50 data-[state=selected]:bg-muted",
        className
      )}
      {...props}
    />
  )
}

function TableHead({ className, ...props }: React.ComponentProps<"th">) {
  return (
    <th
      data-slot="table-head"
      className={cn(
        "h-10 px-2 text-left align-middle font-medium whitespace-nowrap text-foreground [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[2px]",
        className
      )}
      {...props}
    />
  )
}

function TableCell({ className, ...props }: React.ComponentProps<"td">) {
  return (
    <td
      data-slot="table-cell"
      className={cn(
        "p-2 align-middle whitespace-nowrap [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[2px]",
        className
      )}
      {...props}
    />
  )
}

function TableCaption({
  className,
  ...props
}: React.ComponentProps<"caption">) {
  return (
    <caption
      data-slot="table-caption"
      className={cn("mt-4 text-sm text-muted-foreground", className)}
      {...props}
    />
  )
}

export {
  Table,
  TableHeader,
  TableBody,
  TableFooter,
  TableHead,
  TableRow,
  TableCell,
  TableCaption,
}
