import UserAvatar from "@/components/user/UserAvatar";
import ComponentDoc from "@/components/dev/ComponentDoc";

const ada = {
  username: "ada-lovelace",
  firstName: "Ada",
  lastName: "Lovelace",
  personalizedColor: "#0e7490",
};

const lin = {
  username: "lin-mei",
  firstName: "Lin",
  lastName: "Mei",
  personalizedColor: "#9333ea",
};

const UserAvatarPage = () => (
  <ComponentDoc
    name="UserAvatar"
    description="Renders the uploaded image when present, otherwise white initials on the user's `personalizedColor` (a contrast-safe palette picked at signup). Sizes: sm (32px), md (40px), lg (96px)."
    importPath={`import UserAvatar from "@/components/user/UserAvatar";`}
    examples={[
      {
        title: "Initials fallback (no avatarUrls)",
        code: `<UserAvatar
  user={{
    username: "ada-lovelace",
    firstName: "Ada",
    lastName: "Lovelace",
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
    ]}
  />
);

export default UserAvatarPage;
