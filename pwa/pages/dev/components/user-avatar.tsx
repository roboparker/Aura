import UserAvatar from "@/components/user/UserAvatar";
import ComponentDoc from "@/components/dev/ComponentDoc";

const ada = {
  givenName: "Ada",
  familyName: "Lovelace",
  personalizedColor: "#0e7490",
};

const lin = {
  givenName: "Lin",
  familyName: "Mei",
  personalizedColor: "#9333ea",
};

const UserAvatarPage = () => (
  <ComponentDoc
    name="UserAvatar"
    description="Renders the uploaded image when present, otherwise white initials on the user's `personalizedColor` (a contrast-safe palette picked at signup). Sizes: sm (32px), md (40px), lg (96px). Shape: circle (default) or square (rounded edges, used in the sidebar header)."
    importPath={`import UserAvatar from "@/components/user/UserAvatar";`}
    examples={[
      {
        title: "Initials fallback (no avatarUrls)",
        code: `<UserAvatar
  user={{
    givenName: "Ada",
    familyName: "Lovelace",
    personalizedColor: "#0e7490",
  }}
/>`,
        preview: (
          <div className="flex items-center gap-4">
            <UserAvatar user={ada} size="sm" />
            <UserAvatar user={ada} size="md" />
            <UserAvatar user={lin} size="lg" />
          </div>
        ),
      },
      {
        title: "Square shape (rounded edges)",
        code: `<UserAvatar user={ada} shape="square" />`,
        preview: (
          <div className="flex items-center gap-4">
            <UserAvatar user={ada} size="sm" shape="square" />
            <UserAvatar user={ada} size="md" shape="square" />
            <UserAvatar user={lin} size="lg" shape="square" />
          </div>
        ),
      },
    ]}
  />
);

export default UserAvatarPage;
