import { Lock } from "lucide-react";
import type { LandingPage, LandingPreference } from "@/contexts/AuthContext";
import { useActiveSpace, type Space } from "@/contexts/ActiveSpaceContext";
import {
  LANDING_PAGES,
  LANDING_PAGE_LABELS,
} from "@/lib/landingDestination";
import { cn } from "@/lib/utils";
import {
  Combobox,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
} from "@/components/ui/combobox";
import { Label } from "@/components/ui/label";

/**
 * "Start page" picker — chooses where a fresh sign-in (no deep link)
 * lands. Controlled: the parent owns the value and the save. When the
 * "A space" option is active, a second picker chooses which space; the
 * personal "Private" space is stored as a null `spaceId` so it stays the
 * forgiving fallback if any other space becomes inaccessible.
 */
interface StartPageSelectProps {
  value: LandingPreference;
  onChange: (landing: LandingPreference) => void;
  disabled?: boolean;
}

const PAGE_HINTS: Record<LandingPage, string> = {
  space: "Open the workspace in a space you pick.",
  tasks: "Go straight to your task list.",
  notifications: "Open your notification inbox.",
  spaces: "See every space you belong to.",
};

const spaceLabel = (space: Space): string =>
  space.isPersonal ? `${space.name} (personal)` : space.name;

const StartPageSelect = ({ value, onChange, disabled }: StartPageSelectProps) => {
  const { spaces, personalSpace } = useActiveSpace();

  // Resolve the space the stored preference points at. A null spaceId
  // means the personal space; an id that's no longer in the user's list
  // (left / deleted / revoked) resolves to null so the UI shows the
  // fallback rather than a dangling reference.
  const selectedSpace: Space | null =
    value.page === "space"
      ? value.spaceId
        ? spaces.find((s) => s.id === value.spaceId) ?? null
        : personalSpace
      : null;

  const configuredSpaceMissing =
    value.page === "space" && Boolean(value.spaceId) && selectedSpace === null;

  const choosePage = (page: LandingPage) => {
    if (page === "space") {
      // Preserve a still-valid space choice; otherwise default to personal.
      onChange({
        page: "space",
        spaceId: configuredSpaceMissing ? null : value.spaceId,
      });
      return;
    }
    onChange({ page, spaceId: null });
  };

  const chooseSpace = (space: Space | null) => {
    onChange({
      page: "space",
      // The personal space is stored as null so it remains the fallback.
      spaceId: space && !space.isPersonal ? space.id : null,
    });
  };

  return (
    <div className="space-y-3">
      <div className="space-y-1.5">
        <Label>Start page</Label>
        <p className="text-xs text-muted-foreground">
          Where you land after signing in (deep links still take you straight
          to the page you opened).
        </p>
      </div>

      <div
        className="grid gap-2 sm:grid-cols-2"
        role="radiogroup"
        aria-label="Start page"
      >
        {LANDING_PAGES.map((page) => {
          const active = value.page === page;
          return (
            <button
              key={page}
              type="button"
              role="radio"
              aria-checked={active}
              disabled={disabled}
              onClick={() => choosePage(page)}
              data-testid={`start-page-${page}`}
              className={cn(
                "rounded-lg border px-3 py-2.5 text-left transition-colors",
                "disabled:cursor-not-allowed disabled:opacity-60",
                active
                  ? "border-primary bg-primary/5 ring-1 ring-primary"
                  : "border-input hover:bg-accent",
              )}
            >
              <span className="block text-sm font-medium text-foreground">
                {LANDING_PAGE_LABELS[page]}
              </span>
              <span className="mt-0.5 block text-xs text-muted-foreground">
                {PAGE_HINTS[page]}
              </span>
            </button>
          );
        })}
      </div>

      {value.page === "space" && (
        <div className="space-y-1.5">
          <Label htmlFor="start-page-space">Which space</Label>
          <Combobox<Space, false>
            items={spaces}
            value={selectedSpace}
            disabled={disabled || spaces.length === 0}
            onValueChange={chooseSpace}
            itemToStringLabel={spaceLabel}
            itemToStringValue={(s) => s.id}
          >
            <ComboboxInput
              id="start-page-space"
              placeholder="Search spaces…"
              data-testid="start-page-space-input"
            />
            <ComboboxContent>
              <ComboboxEmpty>No matching space.</ComboboxEmpty>
              <ComboboxList>
                {(s: Space) => (
                  <ComboboxItem key={s.id} value={s}>
                    <span className="flex items-center gap-1.5">
                      {s.isPersonal && (
                        <Lock className="h-3 w-3 text-muted-foreground" aria-hidden />
                      )}
                      {spaceLabel(s)}
                    </span>
                  </ComboboxItem>
                )}
              </ComboboxList>
            </ComboboxContent>
          </Combobox>
          {configuredSpaceMissing && (
            <p className="text-xs text-amber-600" data-testid="start-page-space-fallback">
              Your previously chosen space is no longer available — sign-in will
              land in your Private space until you pick another.
            </p>
          )}
        </div>
      )}
    </div>
  );
};

export default StartPageSelect;
