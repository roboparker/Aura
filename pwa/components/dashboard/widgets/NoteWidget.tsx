import MarkdownView from "@/components/editor/MarkdownView";
import type { WidgetProps } from "./registry";

/**
 * A free-text note. Everything it shows lives in its config, so it never
 * fetches — the simplest widget there is, and the reference for a config-only
 * one.
 */
const NoteWidget = ({ widget }: WidgetProps) => {
  const title = typeof widget.config.title === "string" ? widget.config.title : "";
  const body = typeof widget.config.body === "string" ? widget.config.body : "";

  if (title === "" && body === "") {
    return (
      <p className="text-sm text-muted-foreground">
        Empty note — edit it to add text.
      </p>
    );
  }

  return (
    <div className="space-y-2">
      {title !== "" && <p className="font-medium">{title}</p>}
      {body !== "" && (
        <div className="text-sm text-muted-foreground">
          <MarkdownView source={body} />
        </div>
      )}
    </div>
  );
};

export default NoteWidget;
