import AvatarStack from "@/components/user/AvatarStack";
import type { AvatarUser } from "@/components/user/UserAvatar";
import ComponentDoc from "@/components/dev/ComponentDoc";

const USERS: AvatarUser[] = [
  { givenName: "Lin", familyName: "Castellano", email: "lin@example.com", personalizedColor: "#b45309" },
  { givenName: "Theo", familyName: "Wendt", email: "theo@example.com", personalizedColor: "#1d4ed8" },
  { givenName: "Maya", familyName: "Okafor", email: "maya@example.com", personalizedColor: "#7e22ce" },
  { givenName: "Iris", familyName: "Hwang", email: "iris@example.com", personalizedColor: "#15803d" },
];

const AvatarStackPage = () => (
  <ComponentDoc
    name="AvatarStack"
    description="Overlapping cluster of user avatars with a '+N' overflow chip — the 'who's in this thread' affordance on discussion rows and the detail header. Presentation only: the caller passes an already-capped `users` list plus the `total` distinct count for the overflow chip."
    importPath={`import AvatarStack from "@/components/user/AvatarStack";`}
    examples={[
      {
        title: "Within the shown set",
        code: `<AvatarStack users={users.slice(0, 3)} />`,
        preview: <AvatarStack users={USERS.slice(0, 3)} />,
      },
      {
        title: "With overflow",
        code: `<AvatarStack users={users.slice(0, 3)} total={9} />`,
        preview: <AvatarStack users={USERS.slice(0, 3)} total={9} />,
      },
      {
        title: "Medium size",
        code: `<AvatarStack users={users} total={6} size="md" />`,
        preview: <AvatarStack users={USERS} total={6} size="md" />,
      },
    ]}
  />
);

export default AvatarStackPage;
