import Link from "next/link";
import { useRouter } from "next/router";
import {
  ChevronDown,
  ListChecks,
  LogOut,
  MessagesSquare,
  Settings,
  Users,
  VenetianMask,
} from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { displayName } from "@/lib/userDisplay";
import UserAvatar from "@/components/user/UserAvatar";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";

// Personal destinations surfaced through the account menu. Notifications
// live in the bell next to this trigger; Sign Out is appended as an
// action below the divider.
const USER_MENU_LINKS = [
  { href: "/my-tasks", label: "My Tasks", icon: ListChecks },
  { href: "/groups", label: "Manage Groups", icon: Users },
  { href: "/feedback", label: "Feedback", icon: MessagesSquare },
  { href: "/settings/profile", label: "Settings", icon: Settings },
];

/**
 * Account menu rendered in the top bar — a square avatar + chevron
 * trigger that opens a dropdown headed by the signed-in user's name and
 * email, followed by the personal links and Sign Out.
 */
const UserMenu = () => {
  const { user, logout, stopImpersonation } = useAuth();
  const router = useRouter();

  if (!user) return null;

  const impersonator = user.impersonator;
  const handleStopImpersonation = () => {
    void stopImpersonation().then(() => router.push("/"));
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          data-testid="user-menu-trigger"
          aria-label={`Account menu for ${displayName(user)}`}
          className={cn(
            "flex items-center gap-1.5 rounded-md p-1",
            "hover:bg-accent focus:outline-none focus:ring-2 focus:ring-ring",
          )}
        >
          <UserAvatar user={user} size="sm" />
          <ChevronDown
            className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
            aria-hidden
          />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="min-w-[220px]">
        <div className="flex items-center gap-2 px-2 py-1.5">
          <UserAvatar user={user} size="sm" />
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold">{displayName(user)}</p>
            <p className="truncate text-xs text-muted-foreground">
              {user.email}
            </p>
          </div>
        </div>
        <DropdownMenuSeparator />
        {USER_MENU_LINKS.map(({ href, label, icon: Icon }) => (
          <DropdownMenuItem key={href} asChild className="cursor-pointer">
            <Link href={href} className="gap-2">
              <Icon className="h-4 w-4" aria-hidden />
              {label}
            </Link>
          </DropdownMenuItem>
        ))}
        {impersonator && (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              onSelect={handleStopImpersonation}
              className="cursor-pointer gap-2 text-amber-700 focus:text-amber-700 dark:text-amber-400 dark:focus:text-amber-400"
              data-testid="stop-impersonation"
            >
              <VenetianMask className="h-4 w-4" aria-hidden />
              Stop impersonation
            </DropdownMenuItem>
          </>
        )}
        <DropdownMenuSeparator />
        <DropdownMenuItem
          onSelect={logout}
          className="cursor-pointer gap-2 text-destructive focus:text-destructive"
          data-testid="user-menu-signout"
        >
          <LogOut className="h-4 w-4" aria-hidden />
          Sign Out
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
};

export default UserMenu;
