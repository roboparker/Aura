import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/router";
import { Formik, Form } from "formik";
import { Lock } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { fetchWithTimeout } from "@/lib/fetchWithTimeout";
import { isSafeNextPath } from "@/lib/authRedirect";
import {
  MIN_PASSWORD_LENGTH,
  MIN_PASSWORD_STRENGTH,
  estimatePasswordStrength,
} from "@/lib/passwordStrength";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { FormikField } from "@/components/ui/formik-field";
import PasswordStrengthMeter from "./PasswordStrengthMeter";

/**
 * Inviter shown at the top of the invite-signup card. Backend returns
 * the display name, the email (for "Ask Maya to invite a different
 * address" copy + mailto fallback), and the initials/color we render
 * the chip with.
 */
interface InviterChip {
  name: string;
  email: string;
  initials: string;
  color: string;
}

interface SpaceAttachment {
  id: string;
  name: string;
  role: string;
  invitedBy: InviterChip;
}

interface GroupAttachment {
  id: string;
  title: string;
  memberCount: number;
  invitedBy: InviterChip;
}

type InviteResponse =
  | {
      status: "pending";
      email: string;
      inviter: InviterChip | null;
      expiresAt: string;
      spaces: SpaceAttachment[];
      groups: GroupAttachment[];
    }
  | {
      status: "expired";
      email: string;
      inviter: InviterChip | null;
      expiresAt: string;
    }
  | {
      status: "accepted";
      email: string;
      inviter: InviterChip | null;
      expiresAt: string;
      acceptedAt: string;
    };

interface InviteSignupValues {
  givenName: string;
  familyName: string;
  password: string;
}

const validate = (values: InviteSignupValues) => {
  const errors: Partial<Record<keyof InviteSignupValues, string>> = {};
  if (!values.givenName.trim()) errors.givenName = "Given name is required.";
  if (!values.familyName.trim()) errors.familyName = "Family name is required.";
  if (!values.password) {
    errors.password = "Password is required.";
  } else if (values.password.length < MIN_PASSWORD_LENGTH) {
    errors.password = `Password must be at least ${MIN_PASSWORD_LENGTH} characters.`;
  } else if (estimatePasswordStrength(values.password) < MIN_PASSWORD_STRENGTH) {
    errors.password = "Too weak. Use a longer mix of words, numbers, and symbols.";
  }
  return errors;
};

interface Props {
  token: string;
  /** Same-origin `?next=` for the signin redirect after accepting. */
  next?: string;
}

/**
 * The four mockup states an invite link can render:
 *
 *  - `loading` — first paint while we look up the token.
 *  - `pending` — token is good, user not signed in (or signed in as the
 *    invited email): show the form with the invite context above it.
 *  - `mismatch` — token is good but the user is currently signed in as
 *    a different email; offer Sign out & accept / Ignore / Sign out.
 *  - `expired` — token is past its 7-day window.
 *  - `accepted` — token was already redeemed.
 *  - `not-found` — token doesn't resolve (typo, revoked, etc.). Falls
 *    back to the same "expired" framing since the user can't tell them
 *    apart from outside; the body copy is generic enough to cover both.
 */
type State =
  | { kind: "loading" }
  | { kind: "pending"; invite: Extract<InviteResponse, { status: "pending" }> }
  | { kind: "mismatch"; invite: Extract<InviteResponse, { status: "pending" }> }
  | { kind: "expired"; invite: Extract<InviteResponse, { status: "expired" }> | null }
  | { kind: "accepted"; invite: Extract<InviteResponse, { status: "accepted" }> };

const InviteSignup = ({ token, next }: Props) => {
  const { user, register, logout } = useAuth();
  const router = useRouter();
  const [state, setState] = useState<State>({ kind: "loading" });

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const res = await fetchWithTimeout(
          `${ENTRYPOINT}/invites/${encodeURIComponent(token)}`,
        );
        if (cancelled) return;
        if (res.status === 404) {
          setState({ kind: "expired", invite: null });
          return;
        }
        if (!res.ok) {
          setState({ kind: "expired", invite: null });
          return;
        }
        const data = (await res.json()) as InviteResponse;
        if (cancelled) return;
        if (data.status === "expired") {
          setState({ kind: "expired", invite: data });
          return;
        }
        if (data.status === "accepted") {
          setState({ kind: "accepted", invite: data });
          return;
        }
        // status === "pending": branch on whether the visitor is signed
        // in as someone else.
        if (user && user.email.toLowerCase() !== data.email.toLowerCase()) {
          setState({ kind: "mismatch", invite: data });
        } else {
          setState({ kind: "pending", invite: data });
        }
      } catch {
        if (!cancelled) setState({ kind: "expired", invite: null });
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [token, user]);

  if (state.kind === "loading") {
    return <LoadingCard />;
  }
  if (state.kind === "expired") {
    return <ExpiredCard invite={state.invite} />;
  }
  if (state.kind === "accepted") {
    return (
      <AlreadyUsedCard
        invite={state.invite}
        onSignIn={() =>
          router.push(`/signin?email=${encodeURIComponent(state.invite.email)}`)
        }
      />
    );
  }
  if (state.kind === "mismatch") {
    return (
      <MismatchCard
        invite={state.invite}
        signedInEmail={user?.email ?? ""}
        onSignOutAndAccept={async () => {
          await logout();
          // Re-render the page once the auth context clears; the URL
          // stays put so the same token re-fetches as pending instead
          // of mismatch.
          router.replace(router.asPath);
        }}
        onIgnore={() => {
          router.push(isSafeNextPath(next) ? next : "/account");
        }}
        onSignOut={async () => {
          await logout();
          router.push("/signin");
        }}
      />
    );
  }

  return (
    <AcceptInviteCard
      invite={state.invite}
      onSubmit={async (values) => {
        await register({
          email: state.invite.email,
          password: values.password,
          givenName: values.givenName.trim(),
          familyName: values.familyName.trim(),
          inviteToken: token,
        });
        const params = new URLSearchParams({ registered: "true" });
        if (isSafeNextPath(next)) params.set("next", next);
        router.push(`/signin?${params.toString()}`);
      }}
    />
  );
};

// ---------- sub-cards ----------------------------------------------------

const LoadingCard = () => (
  <>
    <CardHeader className="p-8 pb-6">
      <CardTitle className="text-3xl font-bold">Checking your invite…</CardTitle>
    </CardHeader>
    <CardContent className="p-8 pt-2">
      <div className="h-24 animate-pulse rounded-md bg-muted" />
    </CardContent>
  </>
);

const ExpiredCard = ({
  invite,
}: {
  invite: Extract<InviteResponse, { status: "expired" }> | null;
}) => {
  const expired = invite
    ? new Date(invite.expiresAt).toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
        year: "numeric",
      })
    : null;
  return (
    <>
      <CardContent className="p-8 space-y-5 text-center" data-testid="invite-expired">
        <StatusBadge tone="destructive">
          <div className="h-3 w-3 rounded-full bg-destructive" aria-hidden />
        </StatusBadge>
        <div className="space-y-2">
          <h2 className="text-2xl font-bold">This invite has expired</h2>
          <p className="text-sm text-muted-foreground">
            Invites are valid for 7 days.{" "}
            {invite && expired ? (
              <>
                The one to{" "}
                <code className="font-mono text-foreground">{invite.email}</code>{" "}
                expired on <span className="text-foreground">{expired}</span>.{" "}
              </>
            ) : (
              <>This link is no longer valid. </>
            )}
            Ask the sender to send a fresh link.
          </p>
        </div>
        {invite?.inviter && <InviterPill chip={invite.inviter} prefix="invited by" />}
        <div className="space-y-2">
          {invite?.inviter && mailtoFor(invite.inviter.email) && (
            // Only surface "Request a new invite" when we actually know
            // who the inviter was AND the email parses as a safe-looking
            // RFC-shape — otherwise the button has nowhere sensible to
            // send the user, and a malformed string from a poisoned
            // response shouldn't make it into a navigation. For the
            // 404 / unknown-token case the body copy already tells
            // them to ask the sender, so the bottom button is enough.
            <Button
              asChild
              type="button"
              className="w-full"
            >
              <a href={mailtoFor(invite.inviter.email) ?? "#"}>
                Request a new invite
              </a>
            </Button>
          )}
          <Button asChild type="button" variant="outline" className="w-full">
            <Link href="/signin">Sign in with another account</Link>
          </Button>
        </div>
      </CardContent>
    </>
  );
};

const AlreadyUsedCard = ({
  invite,
  onSignIn,
}: {
  invite: Extract<InviteResponse, { status: "accepted" }>;
  onSignIn: () => void;
}) => (
  <>
    <CardContent className="p-8 space-y-5 text-center" data-testid="invite-accepted">
      <StatusBadge tone="primary">
        <div className="h-3 w-3 rounded-full bg-primary" aria-hidden />
      </StatusBadge>
      <div className="space-y-2">
        <h2 className="text-2xl font-bold">This invite was already used</h2>
        <p className="text-sm text-muted-foreground">
          An account for{" "}
          <code className="font-mono text-foreground">{invite.email}</code> already
          exists. Sign in to access your spaces
          {invite.inviter ? (
            <>
              {" "}— or ask{" "}
              <span className="text-foreground">{invite.inviter.name}</span> to invite
              a different address.
            </>
          ) : (
            "."
          )}
        </p>
      </div>
      <div className="space-y-2">
        <Button type="button" className="w-full" onClick={onSignIn}>
          Sign in instead
        </Button>
        <Button asChild type="button" variant="outline" className="w-full">
          <Link href="/signup">Use a different email</Link>
        </Button>
      </div>
    </CardContent>
  </>
);

const MismatchCard = ({
  invite,
  signedInEmail,
  onSignOutAndAccept,
  onIgnore,
  onSignOut,
}: {
  invite: Extract<InviteResponse, { status: "pending" }>;
  signedInEmail: string;
  onSignOutAndAccept: () => void;
  onIgnore: () => void;
  onSignOut: () => void;
}) => (
  <>
    <CardHeader className="p-8 pb-4">
      <CardTitle className="text-3xl font-bold">
        You&apos;re signed in as someone else
      </CardTitle>
      <CardDescription className="text-base">
        This invite is for a different email than the account you&apos;re currently
        using.
      </CardDescription>
    </CardHeader>
    <CardContent className="p-8 pt-0 space-y-5" data-testid="invite-mismatch">
      <div className="rounded-md border border-destructive/30 bg-destructive/5 p-3 space-y-2 text-xs">
        <MismatchRow label="Invite is for" value={invite.email} chip={invite.inviter} />
        <MismatchRow label="You are signed in as" value={signedInEmail} chip={null} />
      </div>
      {(invite.spaces.length > 0 || invite.groups.length > 0) && (
        <AttachmentList invite={invite} compact />
      )}
      <div className="space-y-2">
        <Button type="button" className="w-full" onClick={onSignOutAndAccept}>
          Sign out & accept invite
        </Button>
        <Button type="button" variant="outline" className="w-full" onClick={onIgnore}>
          Ignore invite, stay signed in
        </Button>
        <button
          type="button"
          onClick={onSignOut}
          className="w-full text-center text-sm text-primary font-semibold hover:text-foreground"
        >
          Sign out
        </button>
      </div>
    </CardContent>
  </>
);

const AcceptInviteCard = ({
  invite,
  onSubmit,
}: {
  invite: Extract<InviteResponse, { status: "pending" }>;
  onSubmit: (values: InviteSignupValues) => Promise<void>;
}) => (
  <>
    <CardHeader className="p-8 pb-6">
      <CardTitle className="text-3xl font-bold">Finish setting up your account</CardTitle>
      <CardDescription className="text-base">
        Pick a name and password to accept your invite.
      </CardDescription>
    </CardHeader>
    <CardContent className="p-8 pt-2 space-y-5">
      {invite.inviter && (
        <div className="space-y-3" data-testid="invite-attachments">
          <InviterPill chip={invite.inviter} prefix="invited you to:" />
          <AttachmentList invite={invite} />
        </div>
      )}

      <Formik<InviteSignupValues>
        initialValues={{ givenName: "", familyName: "", password: "" }}
        validate={validate}
        onSubmit={async (values, { setSubmitting, setStatus }) => {
          try {
            await onSubmit(values);
          } catch (err) {
            setStatus(err instanceof Error ? err.message : "Registration failed.");
          } finally {
            setSubmitting(false);
          }
        }}
      >
        {({ isSubmitting, status, values, errors, submitCount }) => {
          const errorCount = Object.keys(errors).length;
          return (
            <Form className="space-y-4" noValidate>
              {status && (
                <Alert variant="destructive">
                  <AlertDescription>{status}</AlertDescription>
                </Alert>
              )}
              {submitCount > 0 && errorCount > 0 && (
                <Alert variant="destructive">
                  <AlertDescription>
                    <p className="font-semibold">Fix the highlighted fields</p>
                    <p className="text-xs mt-1">
                      {errorCount} issue{errorCount === 1 ? "" : "s"} to resolve
                      before you can continue.
                    </p>
                  </AlertDescription>
                </Alert>
              )}

              {/* Email is shown read-only with a lock affordance because the
                  invite is tied to a specific address — the backend won't
                  let it redeem with anything else. */}
              <div className="space-y-1.5">
                <label className="text-sm font-medium">Email</label>
                <div className="relative">
                  <input
                    type="email"
                    value={invite.email}
                    readOnly
                    className="flex h-9 w-full rounded-md border border-input bg-muted px-3 py-1 pr-8 text-base text-muted-foreground shadow-xs"
                    aria-label="Email (locked to invite)"
                  />
                  <Lock
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground"
                    aria-hidden
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <FormikField
                  name="givenName"
                  type="text"
                  autoComplete="given-name"
                  label="Given name"
                />
                <FormikField
                  name="familyName"
                  type="text"
                  autoComplete="family-name"
                  label="Family name"
                />
              </div>

              <FormikField
                name="password"
                type="password"
                label="Password"
                autoComplete="new-password"
                labelAddon={
                  <span className="text-xs text-muted-foreground">min 12 chars</span>
                }
              />
              <PasswordStrengthMeter password={values.password} />

              <Button type="submit" disabled={isSubmitting} className="w-full">
                {isSubmitting ? "Creating account…" : "Accept & create account"}
              </Button>

              <p className="text-xs text-muted-foreground text-center">
                By continuing you agree to Aura&apos;s{" "}
                <Link
                  href="/terms"
                  className="text-primary font-semibold hover:text-foreground"
                >
                  Terms
                </Link>{" "}
                and{" "}
                <Link
                  href="/privacy"
                  className="text-primary font-semibold hover:text-foreground"
                >
                  Privacy Policy
                </Link>
                .
              </p>
            </Form>
          );
        }}
      </Formik>
    </CardContent>
    <div className="border-t border-border bg-background px-8 py-5 text-center text-sm text-muted-foreground">
      Already have an account?{" "}
      <Link
        href="/signin"
        className="text-primary font-semibold hover:text-foreground"
      >
        Sign in &amp; accept
      </Link>
    </div>
  </>
);

// ---------- shared pieces -----------------------------------------------

const InviterPill = ({ chip, prefix }: { chip: InviterChip; prefix: string }) => (
  <div className="flex items-center justify-center gap-2 text-sm">
    <span
      className="flex h-7 w-7 items-center justify-center rounded-md text-xs font-semibold text-white"
      style={{ backgroundColor: chip.color }}
      aria-hidden
    >
      {chip.initials}
    </span>
    <span>
      <span className="font-semibold">{chip.name}</span>{" "}
      <span className="text-muted-foreground">{prefix}</span>
    </span>
  </div>
);

const StatusBadge = ({
  tone,
  children,
}: {
  tone: "destructive" | "primary";
  children: React.ReactNode;
}) => (
  <div
    className={[
      "mx-auto flex h-12 w-12 items-center justify-center rounded-md",
      tone === "destructive" ? "bg-destructive/10" : "bg-primary/10",
    ].join(" ")}
    aria-hidden
  >
    {children}
  </div>
);

const AttachmentList = ({
  invite,
  compact = false,
}: {
  invite: Extract<InviteResponse, { status: "pending" }>;
  compact?: boolean;
}) => (
  <ul
    className={[
      "rounded-md border border-border divide-y divide-border",
      compact ? "text-xs" : "text-sm",
    ].join(" ")}
  >
    {invite.spaces.map((space) => (
      <li
        key={`space-${space.id}`}
        data-testid="invite-attachment-space"
        className="flex items-center gap-3 px-3 py-2"
      >
        <span
          className="flex h-7 w-7 items-center justify-center rounded-md bg-muted text-xs font-semibold"
          aria-hidden
        >
          {space.name[0]?.toUpperCase() ?? "S"}
        </span>
        <span className="flex-1 font-medium">{space.name}</span>
        <span className="text-[10px] uppercase tracking-wider text-muted-foreground">
          Space · {space.role}
        </span>
      </li>
    ))}
    {invite.groups.map((group) => (
      <li
        key={`group-${group.id}`}
        data-testid="invite-attachment-group"
        className="flex items-center gap-3 px-3 py-2"
      >
        <span
          className="flex h-7 w-7 items-center justify-center rounded-md bg-muted text-xs font-semibold"
          aria-hidden
        >
          {group.title[0]?.toUpperCase() ?? "G"}
        </span>
        <span className="flex-1 font-medium">{group.title}</span>
        <span className="text-[10px] uppercase tracking-wider text-muted-foreground">
          Group · {group.memberCount} ppl
        </span>
      </li>
    ))}
  </ul>
);

const MismatchRow = ({
  label,
  value,
  chip,
}: {
  label: string;
  value: string;
  chip: InviterChip | null;
}) => (
  <div className="space-y-0.5">
    <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
      {label}
    </p>
    <div className="flex items-center gap-2 font-mono text-sm">
      {chip && (
        <span
          className="flex h-5 w-5 items-center justify-center rounded-sm text-[10px] font-semibold text-white"
          style={{ backgroundColor: chip.color }}
          aria-hidden
        >
          {chip.initials}
        </span>
      )}
      <span>{value}</span>
    </div>
  </div>
);

/**
 * Builds a safe `mailto:` URL for a backend-supplied address, or null
 * if the value doesn't look like an RFC-shaped email. Two flags fired
 * on a naive `window.location.href = \`mailto:${apiEmail}\`` here:
 * URL-redirection and reflected-XSS, both because the email is
 * user-influenced data flowing into a navigation. Pinning the scheme,
 * validating the local-and-domain shape against a tight regex (no
 * whitespace, no angle brackets, exactly one `@`, dot in the domain),
 * and `encodeURIComponent`-ing both pieces means an attacker can't
 * smuggle non-mailto control characters or HTML through this code
 * path. Callers MUST guard on the return value being non-null before
 * rendering.
 */
const SAFE_EMAIL_RE = /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/;
function mailtoFor(email: string): string | null {
  if (!SAFE_EMAIL_RE.test(email)) return null;
  const [local, domain] = email.split("@");
  const subject = encodeURIComponent(
    "Aura invite expired — could you send a fresh one?",
  );
  return `mailto:${encodeURIComponent(local)}@${encodeURIComponent(domain)}?subject=${subject}`;
}

// Re-export for any future caller that wants to render attachments
// outside the signup card (none today; left intentional so an unused-
// import lint stays loud when something stops using it).
export type { InviterChip };
export default InviteSignup;
