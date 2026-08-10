import { useCallback, useEffect, useState } from "react";
import { Bot, Check, ChevronDown, Copy, MessageSquare, Plus, X } from "lucide-react";
import { useAgentChat } from "@/contexts/AgentChatContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { type SpaceAgent, type SpaceAgentCollection } from "@/lib/agentTypes";
import { type SpaceRole, type SpaceRoleRef } from "@/lib/roleTypes";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import AiCreditsMeter from "@/components/spaces/AiCreditsMeter";

/**
 * Multi-select over the space's custom roles, shared by the create form and
 * each agent row. Deliberately the same mental model as a person's role
 * assignment — an agent's permissions are configured with the same matrix, by
 * design.
 *
 * One difference: an agent with no roles is shown as "No access", not
 * "Member (default)". The server's "a member with zero roles is unrestricted"
 * back-compat rule applies to people who predate roles; an agent is created
 * after them, and its credential is a space-scoped key, which grants nothing
 * without roles. Labelling it "default" would read as *more* access than it
 * has.
 */
const RolePicker = ({
  allRoles,
  assignedIds,
  onToggle,
  label,
}: {
  allRoles: SpaceRole[];
  assignedIds: Set<string>;
  onToggle: (role: SpaceRole) => void;
  label?: string;
}) => (
  <DropdownMenu>
    <DropdownMenuTrigger asChild>
      <button
        type="button"
        className="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium hover:bg-accent focus:outline-none focus:ring-2 focus:ring-ring"
      >
        {label ??
          (assignedIds.size === 0
            ? "No access"
            : `${assignedIds.size} role${assignedIds.size === 1 ? "" : "s"}`)}
        <ChevronDown className="h-3 w-3 text-muted-foreground" aria-hidden />
      </button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" className="min-w-[200px]">
      {allRoles.length === 0 ? (
        <DropdownMenuItem disabled>No roles defined yet</DropdownMenuItem>
      ) : (
        allRoles.map((role) => (
          <DropdownMenuItem
            key={role["@id"]}
            onSelect={(e) => {
              e.preventDefault();
              onToggle(role);
            }}
            className="gap-2"
          >
            <span
              className="h-2.5 w-2.5 shrink-0 rounded-full"
              style={{ backgroundColor: role.color ?? "#6b7280" }}
              aria-hidden
            />
            <span className="min-w-0 flex-1 truncate">{role.name}</span>
            {assignedIds.has(role.id) && (
              <Check className="h-3.5 w-3.5 shrink-0" aria-hidden />
            )}
          </DropdownMenuItem>
        ))
      )}
    </DropdownMenuContent>
  </DropdownMenu>
);

/**
 * One-shot reveal of a newly-minted agent token.
 *
 * Stays until dismissed rather than auto-hiding: only the hash is stored, so
 * a token lost to a re-render cannot be recovered and the agent has to be
 * replaced.
 */
const TokenReveal = ({
  token,
  onDismiss,
}: {
  token: string;
  onDismiss: () => void;
}) => {
  const [copied, setCopied] = useState(false);

  return (
    <Alert className="mt-3">
      <AlertDescription className="space-y-2">
        <p className="text-sm font-medium">
          Copy this token now — it won&rsquo;t be shown again.
        </p>
        <div className="flex items-center gap-2">
          <code className="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1 font-mono text-xs">
            {token}
          </code>
          <Button
            type="button"
            size="sm"
            variant="outline"
            className="gap-1.5 shrink-0"
            onClick={() => {
              void navigator.clipboard?.writeText(token).then(
                () => setCopied(true),
                () => setCopied(false),
              );
            }}
          >
            <Copy className="h-3.5 w-3.5" aria-hidden />
            {copied ? "Copied" : "Copy"}
          </Button>
          <Button type="button" size="sm" variant="ghost" onClick={onDismiss}>
            Done
          </Button>
        </div>
      </AlertDescription>
    </Alert>
  );
};

/**
 * The space's AI agents (#827), on the admin-only Users page next to the
 * human roster — the same place, because an agent is granted access the same
 * way a person is, but a separate card, because it is not one.
 *
 * v1 agents do nothing yet: they exist, hold roles, and hold a token. Chat
 * and the provider wiring are later steps.
 */
const SpaceAgents = ({
  spaceId,
  roles,
  disabled,
}: {
  spaceId: string;
  /** The space's custom roles, already loaded by the host page. */
  roles: SpaceRole[];
  /** True for a private space, where agents don't apply. */
  disabled?: boolean;
}) => {
  const [agents, setAgents] = useState<SpaceAgent[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [draftRoleIds, setDraftRoleIds] = useState<Set<string>>(new Set());
  const [isCreating, setIsCreating] = useState(false);
  const [freshToken, setFreshToken] = useState<string | null>(null);
  const { openChat } = useAgentChat();

  const load = useCallback(async () => {
    const res = await fetch(
      `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/agents`,
      { credentials: "include", headers: { Accept: "application/json" } },
    );
    if (!res.ok) {
      // A caller without the `api_keys` permission simply has no agents
      // section; that isn't an error worth shouting about on a page they can
      // otherwise use.
      setAgents([]);
      return;
    }
    const data: SpaceAgentCollection = await res.json();
    setAgents(data.agents ?? []);
  }, [spaceId]);

  useEffect(() => {
    if (!disabled) void load();
  }, [disabled, load]);

  const irisFor = (ids: Set<string>): string[] =>
    roles.filter((r) => ids.has(r.id)).map((r) => r["@id"]);

  const handleCreate = async () => {
    if (!name.trim()) return;
    setIsCreating(true);
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/agents`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            name: name.trim(),
            roles: irisFor(draftRoleIds),
          }),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || "Failed to create the agent.");
      setFreshToken(typeof data.plainToken === "string" ? data.plainToken : null);
      setName("");
      setDraftRoleIds(new Set());
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create the agent.");
    } finally {
      setIsCreating(false);
    }
  };

  const handleSetRoles = async (agent: SpaceAgent, next: SpaceRoleRef[]) => {
    setError(null);
    const res = await fetch(
      `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/agents/${encodeURIComponent(agent.id)}`,
      {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ roles: next.map((r) => r["@id"]) }),
      },
    );
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      setError(data.error || "Failed to update the agent's roles.");
      return;
    }
    await load();
  };

  const handleRemove = async (agent: SpaceAgent) => {
    if (
      !window.confirm(
        `Remove ${agent.name}? Its access and token are revoked immediately.`,
      )
    ) {
      return;
    }
    setError(null);
    const res = await fetch(
      `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/agents/${encodeURIComponent(agent.id)}`,
      { method: "DELETE", credentials: "include" },
    );
    if (!res.ok && res.status !== 204) {
      const data = await res.json().catch(() => ({}));
      setError(data.error || "Failed to remove the agent.");
      return;
    }
    await load();
  };

  if (disabled) return null;

  return (
    <Card className="mb-6">
      <CardContent className="pt-6 space-y-3">
        <h2 className="font-semibold">
          Agents{" "}
          <span className="text-muted-foreground font-normal">
            {agents.length}
          </span>
        </h2>
        <p className="text-xs text-muted-foreground">
          AI agents are members of this space with their own permissions. They
          can&rsquo;t sign in, don&rsquo;t use a seat, and only ever do what the
          roles you give them allow.
        </p>

        <AiCreditsMeter spaceId={spaceId} />

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <form
          className="flex flex-wrap items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            void handleCreate();
          }}
        >
          <div className="relative flex-1 min-w-[180px]">
            <Bot
              className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"
              aria-hidden
            />
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Name your agent…"
              aria-label="Agent name"
              maxLength={80}
              className="pl-8"
            />
          </div>
          <RolePicker
            allRoles={roles}
            assignedIds={draftRoleIds}
            onToggle={(role) =>
              setDraftRoleIds((prev) => {
                const next = new Set(prev);
                if (next.has(role.id)) next.delete(role.id);
                else next.add(role.id);
                return next;
              })
            }
          />
          <Button
            type="submit"
            size="sm"
            className="gap-1.5"
            disabled={isCreating || !name.trim()}
          >
            <Plus className="h-3.5 w-3.5" aria-hidden />
            {isCreating ? "Creating…" : "New agent"}
          </Button>
        </form>

        {freshToken && (
          <TokenReveal token={freshToken} onDismiss={() => setFreshToken(null)} />
        )}

        {agents.length === 0 ? (
          <div className="rounded-md border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
            No agents yet.
          </div>
        ) : (
          <ul className="divide-y divide-border rounded-md border">
            {agents.map((agent) => (
              <li
                key={agent["@id"]}
                className="flex items-center gap-3 px-3 py-2.5"
              >
                <span
                  className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-white"
                  style={{ backgroundColor: agent.personalizedColor }}
                  aria-hidden
                >
                  <Bot className="h-4 w-4" />
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-1.5">
                    <span className="font-medium truncate">{agent.name}</span>
                    <span className="rounded border px-1 text-[10px] uppercase tracking-wide text-muted-foreground">
                      agent
                    </span>
                  </div>
                  <p className="text-xs text-muted-foreground truncate">
                    {agent.roles.length === 0
                      ? "No access"
                      : agent.roles.map((r) => r.name).join(", ")}
                  </p>
                </div>
                {/* The only way into the dock until the left-nav Agents
                    section lands. Chatting is open to any space member; only
                    the role and remove controls beside it are admin work. */}
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  className="gap-1.5"
                  onClick={() =>
                    openChat({
                      id: agent.id,
                      name: agent.name,
                      personalizedColor: agent.personalizedColor,
                    })
                  }
                >
                  <MessageSquare className="h-3.5 w-3.5" aria-hidden />
                  Chat
                </Button>
                <RolePicker
                  allRoles={roles}
                  assignedIds={new Set(agent.roles.map((r) => r.id))}
                  onToggle={(role) => {
                    const assigned = agent.roles.some((r) => r.id === role.id)
                      ? agent.roles.filter((r) => r.id !== role.id)
                      : [...agent.roles, role];
                    void handleSetRoles(agent, assigned);
                  }}
                />
                <button
                  type="button"
                  onClick={() => void handleRemove(agent)}
                  aria-label={`Remove ${agent.name}`}
                  className="text-muted-foreground hover:text-destructive p-1"
                >
                  <X className="h-4 w-4" aria-hidden />
                </button>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
};

export default SpaceAgents;
