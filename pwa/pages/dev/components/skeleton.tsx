import { Skeleton, SkeletonList } from "@/components/ui/skeleton";
import ComponentDoc from "@/components/dev/ComponentDoc";
import { Card, CardContent } from "@/components/ui/card";

const SkeletonPage = () => (
  <ComponentDoc
    name="Skeleton"
    description="Pulsing placeholders for content that hasn't arrived yet. Use these instead of a line of text that says the content is loading — a shape tells you what's coming, a sentence only tells you to wait."
    importPath={`import { Skeleton, SkeletonList } from "@/components/ui/skeleton";`}
    examples={[
      {
        title: "Skeleton",
        code: `<Skeleton className="h-4 w-48" />
<Skeleton className="h-8 w-64" />
<Skeleton className="h-24 w-full" />`,
        preview: (
          <div className="space-y-2">
            <Skeleton className="h-4 w-48" />
            <Skeleton className="h-8 w-64" />
            <Skeleton className="h-24 w-full" />
          </div>
        ),
      },
      {
        title: "SkeletonList",
        code: `{/* The common case: N identical shapes standing in for a list or
    a set of table rows. Size itemClassName off the real row. */}
<SkeletonList rows={4} label="Loading boards" itemClassName="h-[4.5rem]" />`,
        preview: (
          <SkeletonList rows={4} label="Loading boards" itemClassName="h-[4.5rem]" />
        ),
      },
      {
        title: "Inside the surface it replaces",
        code: `{/* Put the placeholder inside the Card/table wrapper the content
    will land in, so the surface itself doesn't appear late. */}
<Card>
  <CardContent className="p-4">
    <SkeletonList rows={5} label="Loading tasks" itemClassName="h-14" />
  </CardContent>
</Card>`,
        preview: (
          <Card>
            <CardContent className="p-4">
              <SkeletonList rows={5} label="Loading tasks" itemClassName="h-14" />
            </CardContent>
          </Card>
        ),
      },
      {
        title: "Composing a non-list shape",
        code: `{/* When the thing loading isn't a list, drop to Skeleton and own
    the region yourself. Only ONE role="status" per loading area —
    nesting a SkeletonList inside this would announce twice. */}
<div role="status" aria-busy="true" className="space-y-4">
  <span className="sr-only">Loading board</span>
  <Skeleton className="h-8 w-64" />
  <Skeleton className="h-9 w-96 max-w-full" />
</div>`,
        preview: (
          <div role="status" aria-busy="true" className="space-y-4">
            <span className="sr-only">Loading board</span>
            <Skeleton className="h-8 w-64" />
            <Skeleton className="h-9 w-96 max-w-full" />
          </div>
        ),
      },
      {
        title: "Accessibility",
        code: `{/* Skeleton is always aria-hidden — there is nothing useful to say
    about a shape that stands for content which doesn't exist yet,
    and eight of them would say it eight times.

    SkeletonList carries role="status" + aria-busy and a visually
    hidden label. The label is real text, not aria-label: an empty
    live region announces nothing when it is already present at
    first paint, which is when these almost always appear. */}
<SkeletonList rows={2} label="Loading comments" itemClassName="h-16" />`,
        preview: (
          <SkeletonList rows={2} label="Loading comments" itemClassName="h-16" />
        ),
      },
    ]}
  />
);

export default SkeletonPage;
