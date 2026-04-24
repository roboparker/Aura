export interface AvatarUser {
  givenName: string;
  familyName: string;
  nickname?: string | null;
  personalizedColor: string;
  avatarUrls?: { thumb?: string; profile?: string } | null;
}

type Size = "sm" | "md" | "lg";

const SIZE_PX: Record<Size, number> = { sm: 32, md: 40, lg: 96 };
const SIZE_TEXT: Record<Size, string> = {
  sm: "text-xs",
  md: "text-sm",
  lg: "text-2xl",
};

const initialsFor = (user: AvatarUser): string => {
  const nick = user.nickname?.trim();
  if (nick) return nick.charAt(0).toUpperCase();
  const g = user.givenName?.charAt(0) ?? "";
  const f = user.familyName?.charAt(0) ?? "";
  return (g + f).toUpperCase() || "?";
};

interface Props {
  user: AvatarUser;
  size?: Size;
  className?: string;
}

const UserAvatar = ({ user, size = "md", className = "" }: Props) => {
  const px = SIZE_PX[size];
  const useThumb = size !== "lg";
  const src = useThumb ? user.avatarUrls?.thumb : user.avatarUrls?.profile;

  const sharedClass =
    `rounded-full inline-block overflow-hidden select-none ${className}`.trim();

  if (src) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={src}
        alt=""
        width={px}
        height={px}
        className={`${sharedClass} object-cover`}
        style={{ width: px, height: px }}
      />
    );
  }

  return (
    <span
      aria-hidden
      className={`${sharedClass} flex items-center justify-center font-semibold text-white ${SIZE_TEXT[size]}`}
      style={{
        backgroundColor: user.personalizedColor,
        width: px,
        height: px,
      }}
    >
      {initialsFor(user)}
    </span>
  );
};

export default UserAvatar;
