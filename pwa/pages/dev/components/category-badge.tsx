import CategoryBadge, {
  CATEGORY_ORDER,
} from "@/components/discussions/CategoryBadge";
import ComponentDoc from "@/components/dev/ComponentDoc";

const CategoryBadgePage = () => (
  <ComponentDoc
    name="CategoryBadge"
    description="Colored-dot pill naming a discussion's category (general / ideas / announcements / q-and-a). One source of truth for the per-category color language lives alongside it as `CATEGORY_META`, `CATEGORY_ORDER`, and `CATEGORY_LABEL`. Classes are dual-mode (light/dark)."
    importPath={`import CategoryBadge, { CATEGORY_META, CATEGORY_ORDER } from "@/components/discussions/CategoryBadge";`}
    examples={[
      {
        title: "All categories",
        code: `{CATEGORY_ORDER.map((c) => (
  <CategoryBadge key={c} category={c} />
))}`,
        preview: (
          <div className="flex flex-wrap items-center gap-2">
            {CATEGORY_ORDER.map((c) => (
              <CategoryBadge key={c} category={c} />
            ))}
          </div>
        ),
      },
    ]}
  />
);

export default CategoryBadgePage;
