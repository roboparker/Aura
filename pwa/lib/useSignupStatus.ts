import { useQuery } from "@tanstack/react-query";
import { ENTRYPOINT } from "@/config/entrypoint";
import { fetchWithTimeout } from "@/lib/fetchWithTimeout";

/**
 * Public signup gate. Mirrors the API's `GET /signup-status` — `true` means the
 * instance is in waitlist mode, so the public CTAs ("Sign up" / "Get started")
 * read as "Join the waitlist" instead. Unauthenticated and cheap; cached across
 * the navbar + landing page + auth card so the label stays consistent.
 *
 * Errors fall back to open signups — the server still enforces the real gate,
 * and a stray "Sign up" label on a waitlisted instance just lands the visitor
 * on the signup page, which swaps to the waitlist flow on its own.
 */
export function useSignupStatus(): { waitlistEnabled: boolean; isLoading: boolean } {
  const query = useQuery({
    queryKey: ["signup-status"],
    queryFn: async (): Promise<boolean> => {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/signup-status`);
      if (!res.ok) return false;
      const data = await res.json();
      return Boolean(data.waitlistEnabled);
    },
    staleTime: 5 * 60 * 1000,
  });

  return { waitlistEnabled: query.data ?? false, isLoading: query.isLoading };
}
