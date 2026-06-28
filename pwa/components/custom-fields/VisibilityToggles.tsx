import { cn } from "@/lib/utils";
import type { CustomFieldVisibility } from "@/components/custom-fields/types";

/**
 * Per-field List + Board visibility toggles. Visibility is a single enum
 * (list | board | both), so the two toggles can't both be off — the last
 * active one is locked to keep the field visible somewhere (the task detail
 * drawer always shows every field regardless).
 */
const VisibilityToggles = ({
  visibility,
  editable,
  onChange,
}: {
  visibility: CustomFieldVisibility;
  editable: boolean;
  onChange: (visibility: CustomFieldVisibility) => void;
}) => {
  const showList = visibility !== "board";
  const showBoard = visibility !== "list";
  const apply = (nextList: boolean, nextBoard: boolean) => {
    if (!nextList && !nextBoard) return;
    onChange(nextList && nextBoard ? "both" : nextList ? "list" : "board");
  };
  const chipClass = (active: boolean, disabled: boolean) =>
    cn(
      "rounded-full border px-2.5 py-0.5 text-xs font-medium transition",
      active
        ? "border-primary bg-primary text-primary-foreground"
        : "border-input bg-muted/40 text-muted-foreground hover:text-foreground",
      disabled && "cursor-not-allowed opacity-60 hover:text-muted-foreground",
    );
  const listLocked = !editable || (showList && !showBoard);
  const boardLocked = !editable || (showBoard && !showList);
  return (
    <div className="flex gap-1" data-testid="custom-field-visibility">
      <button
        type="button"
        aria-pressed={showList}
        disabled={listLocked}
        onClick={() => apply(!showList, showBoard)}
        className={chipClass(showList, listLocked)}
        data-testid="custom-field-visibility-list"
      >
        List
      </button>
      <button
        type="button"
        aria-pressed={showBoard}
        disabled={boardLocked}
        onClick={() => apply(showList, !showBoard)}
        className={chipClass(showBoard, boardLocked)}
        data-testid="custom-field-visibility-board"
      >
        Board
      </button>
    </div>
  );
};

export default VisibilityToggles;
