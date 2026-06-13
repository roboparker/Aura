import { initialsFor, type NamedUser } from "@/lib/userDisplay";

export interface AvatarUser extends NamedUser {
  personalizedColor: string;
  avatarUrls?: { thumb?: string; profile?: string } | null;
}

type Size = "sm" | "md" | "lg";
type Shape = "circle" | "square";

const SIZE_PX: Record<Size, number> = { sm: 32, md: 40, lg: 96 };
const SIZE_TEXT: Record<Size, string> = {
  sm: "text-xs",
  md: "text-sm",
  lg: "text-2xl",
};
// Square avatars get softly rounded corners that scale with the avatar
// so the radius stays proportional at every size.
const SHAPE_RADIUS: Record<Shape, Record<Size, string>> = {
  circle: { sm: "rounded-full", md: "rounded-full", lg: "rounded-full" },
  square: { sm: "rounded-md", md: "rounded-lg", lg: "rounded-2xl" },
};

interface Props {
  user: AvatarUser;
  size?: Size;
  /** Circle (default) or a square with rounded edges. */
  shape?: Shape;
  className?: string;
}

const UserAvatar = ({
  user,
  size = "md",
  shape = "circle",
  className = "",
}: Props) => {
  const px = SIZE_PX[size];
  const useThumb = size !== "lg";
  const src = useThumb ? user.avatarUrls?.thumb : user.avatarUrls?.profile;

  const sharedClass =
    `${SHAPE_RADIUS[shape][size]} overflow-hidden select-none shrink-0 ${className}`.trim();

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
