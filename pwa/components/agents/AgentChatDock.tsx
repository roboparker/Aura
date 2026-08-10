import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { Bot, Loader2, Minus, Send, Trash2, X } from "lucide-react";
import { useAgentChat } from "@/contexts/AgentChatContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import {
  type AgentConversation,
  type AgentMessage,
} from "@/lib/agentTypes";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";

/**
 * The Messenger-style chat dock (#827, step 3) — pinned bottom-right, not a
 * route, so it survives navigation and doesn't take over the page someone is
 * working on.
 *
 * Mounted once in `Layout` and renders nothing until an agent is opened, so it
 * costs no DOM for the overwhelming majority of sessions.
 *
 * v1 is send-and-wait: a spinner while the model answers, then the reply. The
 * issue calls streaming a nice-to-have that shouldn't block v1, and a
 * non-streaming turn is genuinely fine at chat length — the cost of streaming
 * is a second transport (SSE) and a partial-message state in storage, both of
 * which want to be designed rather than bolted on.
 */
const AgentChatDock = () => {
  const { openAgent, closeChat } = useAgentChat();
  const [conversation, setConversation] = useState<AgentConversation | null>(null);
  const [messages, setMessages] = useState<AgentMessage[]>([]);
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [minimized, setMinimized] = useState(false);
  const scrollRef = useRef<HTMLDivElement | null>(null);

  const agentId = openAgent?.id ?? null;

  useEffect(() => {
    if (!agentId) {
      setConversation(null);
      setMessages([]);
      setError(null);
      setDraft("");
      return;
    }
    let cancelled = false;
    setLoading(true);
    const load = async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/agents/${encodeURIComponent(agentId)}/chat`,
          { credentials: "include", headers: { Accept: "application/json" } },
        );
        if (!res.ok) throw new Error("This agent isn't available.");
        const data: AgentConversation = await res.json();
        if (cancelled) return;
        setConversation(data);
        setMessages(data.messages);
        setError(null);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : "Failed to open the chat.");
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    };
    void load();
    return () => {
      cancelled = true;
    };
  }, [agentId]);

  // Pin to the newest turn — including while the model is thinking, so the
  // spinner is what you're looking at rather than something below the fold.
  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
  }, [messages, sending]);

  const send = useCallback(async () => {
    const body = draft.trim();
    if (!agentId || !body || sending) return;
    setSending(true);
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/agents/${encodeURIComponent(agentId)}/chat/messages`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ body }),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || "The agent couldn't answer.");
      setMessages((prev) => [...prev, data.userMessage, data.assistantMessage]);
      // Only cleared on success. The server rolls the whole exchange back on
      // failure, so keeping the draft is what makes "try again" mean exactly
      // one more attempt rather than a duplicate turn.
      setDraft("");
    } catch (err) {
      setError(err instanceof Error ? err.message : "The agent couldn't answer.");
    } finally {
      setSending(false);
    }
  }, [agentId, draft, sending]);

  const handleClear = async () => {
    if (!agentId) return;
    if (!window.confirm("Clear this conversation? It can't be recovered.")) return;
    await fetch(`${ENTRYPOINT}/agents/${encodeURIComponent(agentId)}/chat`, {
      method: "DELETE",
      credentials: "include",
    });
    setMessages([]);
  };

  if (!openAgent) return null;

  const blocked = conversation?.unavailableReason ?? null;
  const needsUpgrade =
    blocked === "plan_not_entitled" || blocked === "credits_exhausted";

  return (
    <div
      className="fixed bottom-0 right-4 z-50 w-[min(24rem,calc(100vw-2rem))] rounded-t-lg border border-b-0 bg-card shadow-lg"
      role="dialog"
      aria-label={`Chat with ${openAgent.name}`}
    >
      <div className="flex items-center gap-2 border-b px-3 py-2">
        <span
          className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-white"
          style={{ backgroundColor: openAgent.personalizedColor ?? "#64748b" }}
          aria-hidden
        >
          <Bot className="h-3.5 w-3.5" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium">{openAgent.name}</p>
          {conversation && (
            <p className="truncate text-xs text-muted-foreground">
              {conversation.space.name}
            </p>
          )}
        </div>
        {messages.length > 0 && (
          <button
            type="button"
            onClick={() => void handleClear()}
            aria-label="Clear conversation"
            className="p-1 text-muted-foreground hover:text-destructive"
          >
            <Trash2 className="h-4 w-4" aria-hidden />
          </button>
        )}
        <button
          type="button"
          onClick={() => setMinimized((m) => !m)}
          aria-label={minimized ? "Expand chat" : "Minimize chat"}
          className="p-1 text-muted-foreground hover:text-foreground"
        >
          <Minus className="h-4 w-4" aria-hidden />
        </button>
        <button
          type="button"
          onClick={closeChat}
          aria-label="Close chat"
          className="p-1 text-muted-foreground hover:text-foreground"
        >
          <X className="h-4 w-4" aria-hidden />
        </button>
      </div>

      {!minimized && (
        <>
          <div
            ref={scrollRef}
            className="h-72 space-y-2 overflow-y-auto px-3 py-3"
            aria-live="polite"
          >
            {loading && (
              <p className="text-center text-xs text-muted-foreground">Opening…</p>
            )}
            {!loading && messages.length === 0 && !blocked && (
              <p className="px-2 py-8 text-center text-sm text-muted-foreground">
                Say hello to {openAgent.name}.
              </p>
            )}
            {messages.map((message) => (
              <div
                key={message.id}
                className={cn(
                  "flex",
                  message.role === "assistant" ? "justify-start" : "justify-end",
                )}
              >
                <div
                  className={cn(
                    "max-w-[85%] whitespace-pre-wrap rounded-lg px-3 py-2 text-sm",
                    message.role === "assistant"
                      ? "bg-muted"
                      : "bg-primary text-primary-foreground",
                  )}
                >
                  {message.body}
                  {message.truncated && (
                    <span className="mt-1 block text-xs opacity-70">
                      (cut off — ask for the rest)
                    </span>
                  )}
                </div>
              </div>
            ))}
            {sending && (
              <div className="flex justify-start">
                <div className="rounded-lg bg-muted px-3 py-2">
                  <Loader2
                    className="h-4 w-4 animate-spin text-muted-foreground"
                    aria-label={`${openAgent.name} is thinking`}
                  />
                </div>
              </div>
            )}
          </div>

          {(error || conversation?.unavailableMessage) && (
            <p
              role="alert"
              className="border-t px-3 py-2 text-xs text-destructive"
            >
              {error ?? conversation?.unavailableMessage}
              {needsUpgrade && !error && (
                <>
                  {" "}
                  <Link href="/pricing" className="text-primary hover:underline">
                    See plans
                  </Link>
                </>
              )}
            </p>
          )}

          <form
            className="flex items-end gap-2 border-t px-3 py-2"
            onSubmit={(e) => {
              e.preventDefault();
              void send();
            }}
          >
            <Textarea
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => {
                // Enter sends, Shift+Enter breaks the line — the convention
                // every chat box already trained people on.
                if (e.key === "Enter" && !e.shiftKey) {
                  e.preventDefault();
                  void send();
                }
              }}
              placeholder={blocked ? "Unavailable" : "Message…"}
              aria-label={`Message ${openAgent.name}`}
              rows={1}
              maxLength={conversation?.maxMessageLength ?? 4000}
              disabled={!!blocked || sending}
              className="max-h-24 min-h-9 resize-none py-2"
            />
            <Button
              type="submit"
              size="icon"
              className="h-9 w-9 shrink-0"
              disabled={!!blocked || sending || !draft.trim()}
              aria-label="Send"
            >
              <Send className="h-4 w-4" aria-hidden />
            </Button>
          </form>
        </>
      )}
    </div>
  );
};

export default AgentChatDock;
