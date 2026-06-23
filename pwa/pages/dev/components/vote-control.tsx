import { useState } from "react";
import VoteControl from "@/components/feedback/VoteControl";
import ComponentDoc from "@/components/dev/ComponentDoc";

/**
 * Interactive demo wrapper — the control is presentational, so the doc
 * owns the vote state to show the toggle (re-click clears, opposite
 * flips) the way the feedback pages drive it against the API.
 */
const Demo = ({
  orientation,
  size,
}: {
  orientation?: "vertical" | "horizontal";
  size?: "sm" | "md";
}) => {
  const [myVote, setMyVote] = useState(0);
  const [score, setScore] = useState(12);

  const vote = (value: 1 | -1) => {
    if (myVote === value) {
      setScore((s) => s - value);
      setMyVote(0);
    } else {
      setScore((s) => s - myVote + value);
      setMyVote(value);
    }
  };

  return (
    <VoteControl
      score={score}
      myVote={myVote}
      onVote={vote}
      orientation={orientation}
      size={size}
    />
  );
};

const VoteControlPage = () => (
  <ComponentDoc
    name="VoteControl"
    description="Up/down vote control for a feedback ticket. Presentational: it shows the net score and highlights the caller's own vote, calling onVote(1 | -1) — the parent owns the API round-trip and the clear/flip toggle. Vertical by default; horizontal for a compact inline pill."
    importPath={`import VoteControl from "@/components/feedback/VoteControl";`}
    examples={[
      {
        title: "Vertical (default)",
        description: "Click the same arrow twice to clear; the opposite arrow flips.",
        code: `<VoteControl score={score} myVote={myVote} onVote={vote} />`,
        preview: (
          <div className="flex items-center gap-8">
            <Demo />
            <Demo size="sm" />
          </div>
        ),
      },
      {
        title: "Horizontal",
        code: `<VoteControl orientation="horizontal" score={score} myVote={myVote} onVote={vote} />`,
        preview: <Demo orientation="horizontal" />,
      },
    ]}
  />
);

export default VoteControlPage;
