import { useEffect, useState } from "react";
import type {
  DashboardWidget,
  WidgetConfigField,
} from "@/lib/dashboardTypes";
import { GRID_COLUMNS } from "@/lib/dashboardTypes";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import {
  Sheet,
  SheetContent,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";

const SELECT_CLASS =
  "flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring";

interface Props {
  widget: DashboardWidget | null;
  saving: boolean;
  error: string | null;
  onSave: (config: Record<string, unknown>, width: number, height: number) => void;
  onClose: () => void;
}

/**
 * Edits one widget's settings from its **server-declared schema** — this
 * component knows nothing about any particular widget.
 *
 * That's what keeps the plugin story honest: a new widget type gets a working
 * config editor for free, as long as its fields use the shared control
 * vocabulary. A widget needing something richer can ship its own editor later
 * without changing this one.
 */
const WidgetConfigSheet = ({ widget, saving, error, onSave, onClose }: Props) => {
  const [config, setConfig] = useState<Record<string, unknown>>({});
  const [width, setWidth] = useState(2);
  const [height, setHeight] = useState(2);
  // Bumped on every open so the remount key below changes even when the same
  // widget is configured twice in a row.
  const [openSeq, setOpenSeq] = useState(0);

  useEffect(() => {
    if (widget) {
      setConfig(widget.config ?? {});
      setWidth(widget.width);
      setHeight(widget.height);
      setOpenSeq((n) => n + 1);
    }
  }, [widget]);

  const set = (key: string, value: unknown) =>
    setConfig((prev) => ({ ...prev, [key]: value }));

  const field = (schema: WidgetConfigField) => {
    const value = config[schema.key];
    const id = `wcfg-${schema.key}`;

    switch (schema.type) {
      case "boolean":
        return (
          <div key={schema.key} className="flex items-center justify-between gap-3">
            <Label htmlFor={id}>{schema.label}</Label>
            <Switch
              id={id}
              checked={value === true}
              onCheckedChange={(checked) => set(schema.key, checked)}
            />
          </div>
        );

      case "select":
        return (
          <div key={schema.key} className="space-y-1.5">
            <Label htmlFor={id}>{schema.label}</Label>
            <select
              id={id}
              className={SELECT_CLASS}
              value={String(value ?? "")}
              onChange={(e) => {
                // Options carry their own value type; a numeric option must go
                // back as a number or the server's is_int() check rejects it.
                const picked = schema.options?.find(
                  (o) => String(o.value) === e.target.value,
                );
                set(schema.key, picked ? picked.value : e.target.value);
              }}
            >
              {schema.options?.map((option) => (
                <option key={String(option.value)} value={String(option.value)}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
        );

      case "number":
        return (
          <div key={schema.key} className="space-y-1.5">
            <Label htmlFor={id}>{schema.label}</Label>
            <Input
              id={id}
              type="number"
              min={schema.min}
              max={schema.max}
              value={typeof value === "number" ? value : ""}
              onChange={(e) =>
                set(schema.key, e.target.value === "" ? undefined : Number(e.target.value))
              }
            />
          </div>
        );

      case "markdown":
        return (
          <div key={schema.key} className="space-y-1.5">
            <Label htmlFor={id}>{schema.label}</Label>
            <Textarea
              id={id}
              rows={6}
              placeholder={schema.placeholder}
              value={typeof value === "string" ? value : ""}
              onChange={(e) => set(schema.key, e.target.value)}
            />
          </div>
        );

      default:
        return (
          <div key={schema.key} className="space-y-1.5">
            <Label htmlFor={id}>{schema.label}</Label>
            <Input
              id={id}
              placeholder={schema.placeholder}
              value={typeof value === "string" ? value : ""}
              onChange={(e) => set(schema.key, e.target.value)}
            />
          </div>
        );
    }
  };

  return (
    <Sheet
      open={widget !== null}
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
    >
      {/* Remounted on every open. Radix ≥1.6 leaves a reused controlled Sheet
          stuck closed if it's reopened mid-exit-animation, and keying on the
          widget id alone wouldn't change when the same widget is configured
          twice in a row. */}
      <SheetContent key={`${widget?.id ?? "none"}-${openSeq}`} className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{widget?.name ?? "Widget"}</SheetTitle>
        </SheetHeader>

        <div className="space-y-4 px-4">
          {widget?.configSchema.map(field)}

          <div className="grid grid-cols-2 gap-3 border-t pt-4">
            <div className="space-y-1.5">
              <Label htmlFor="wcfg-width">Width</Label>
              <select
                id="wcfg-width"
                className={SELECT_CLASS}
                value={width}
                onChange={(e) => setWidth(Number(e.target.value))}
              >
                {Array.from({ length: GRID_COLUMNS }, (_, i) => i + 1).map((n) => (
                  <option key={n} value={n}>
                    {n} of {GRID_COLUMNS}
                  </option>
                ))}
              </select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="wcfg-height">Height</Label>
              <select
                id="wcfg-height"
                className={SELECT_CLASS}
                value={height}
                onChange={(e) => setHeight(Number(e.target.value))}
              >
                {[1, 2, 3, 4].map((n) => (
                  <option key={n} value={n}>
                    {["Short", "Medium", "Tall", "Full"][n - 1]}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {error && <p className="text-sm text-destructive">{error}</p>}
        </div>

        <SheetFooter>
          <Button variant="outline" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button onClick={() => onSave(config, width, height)} disabled={saving}>
            {saving ? "Saving…" : "Save"}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  );
};

export default WidgetConfigSheet;
