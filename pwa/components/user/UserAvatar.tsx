import { initialsFor, type NamedUser } from "@/lib/userDisplay";

export interface AvatarUser extends NamedUser {
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
    `rounded-full overflow-hidden select-none shrink-0 ${className}`.trim();

  if (src) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={src}
        alt=""
        width={px}
        height={px}
        className={`${sharedClass} inline-block object-cover`}
        style={{ width: px, height: px }}
      />
    );
  }

  return (
    <span
      aria-hidden
      className={`${sharedClass} inline-flex items-center justify-center leading-none font-semibold text-white ${SIZE_TEXT[size]}`}
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
