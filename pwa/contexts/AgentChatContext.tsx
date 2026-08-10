import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from "react";

/**
 * Which agent the chat dock is showing, if any (#827, step 3).
 *
 * A context rather than local state on one page because the dock is **not a
 * route** — it stays open across navigation, which is the whole point of a
 * dock. Any surface that lists agents can open it (today the space Users page;
 * the left-nav Agents section in step 4) without knowing where the dock is
 * mounted.
 *
 * Only the identity is held here. The conversation itself is loaded by the dock
 * from the API, so opening a chat needs nothing but an id and a name — a caller
 * with a partial agent row doesn't have to fetch one first.
 */
export interface OpenAgent {
  id: string;
  name: string;
  personalizedColor?: string;
}

interface AgentChatContextValue {
  openAgent: OpenAgent | null;
  openChat: (agent: OpenAgent) => void;
  closeChat: () => void;
}

const AgentChatContext = createContext<AgentChatContextValue>({
  openAgent: null,
  openChat: () => undefined,
  closeChat: () => undefined,
});

export const AgentChatProvider = ({ children }: { children: ReactNode }) => {
  const [openAgent, setOpenAgent] = useState<OpenAgent | null>(null);

  const openChat = useCallback((agent: OpenAgent) => setOpenAgent(agent), []);
  const closeChat = useCallback(() => setOpenAgent(null), []);

  const value = useMemo(
    () => ({ openAgent, openChat, closeChat }),
    [openAgent, openChat, closeChat],
  );

  return (
    <AgentChatContext.Provider value={value}>
      {children}
    </AgentChatContext.Provider>
  );
};

export const useAgentChat = (): AgentChatContextValue =>
  useContext(AgentChatContext);
