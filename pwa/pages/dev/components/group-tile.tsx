import GroupTile from "@/components/groups/GroupTile";
import ComponentDoc from "@/components/dev/ComponentDoc";

const GroupTilePage = () => (
  <ComponentDoc
    name="GroupTile"
    description="Square colored tile for a UserGroup, mirroring SpaceTile but stamped with a 2×2 grid glyph so groups read differently from spaces and users at a glance. Color is resolved by the caller (see `resolveGroupColor` in `@/lib/avatarPalette`). Sizes: sm (32px), md (48px), lg (64px)."
    importPath={`import GroupTile from "@/components/groups/GroupTile";`}
    examples={[
      {
        title: "Sizes",
        code: `<GroupTile color="#15803d" size="sm" />
<GroupTile color="#15803d" size="md" />
<GroupTile color="#15803d" size="lg" />`,
        preview: (
          <div className="flex items-center gap-4">
            <GroupTile color="#15803d" size="sm" />
            <GroupTile color="#15803d" size="md" />
            <GroupTile color="#15803d" size="lg" />
          </div>
        ),
      },
      {
        title: "Palette",
        code: `<GroupTile color="#1d4ed8" />
<GroupTile color="#7e22ce" />
<GroupTile color="#b45309" />`,
        preview: (
          <div className="flex items-center gap-3">
            {["#15803d", "#1d4ed8", "#7e22ce", "#b45309", "#be185d"].map((c) => (
              <GroupTile key={c} color={c} />
            ))}
          </div>
        ),
      },
    ]}
  />
);

export default GroupTilePage;
