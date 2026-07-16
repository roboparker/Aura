import { useCallback, useEffect, useState } from "react";
import { Plus } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { ExpenseCategoryRow } from "@/lib/expenseTypes";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";

/**
 * Managed expense categories (#671) — space-admin card on the space
 * settings page: curated list members pick from when logging expenses,
 * with an optional unit price for mileage-style categories. Archiving
 * keeps history but removes the category from the picker.
 */
const ExpenseCategoriesCard = ({ spaceIri }: { spaceIri: string }) => {
  const [categories, setCategories] = useState<ExpenseCategoryRow[]>([]);
  const [name, setName] = useState("");
  const [unitPrice, setUnitPrice] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetch(
        `${ENTRYPOINT}/expense_categories?space=${encodeURIComponent(spaceIri)}`,
        { credentials: "include", headers: { Accept: "application/ld+json" } },
      );
      if (!res.ok) throw new Error();
      const data = await res.json();
      setCategories(data.member ?? data["hydra:member"] ?? []);
    } catch {
      setError("Failed to load expense categories.");
    }
  }, [spaceIri]);

  useEffect(() => {
    void load();
  }, [load]);

  const add = async () => {
    if (!name.trim()) return;
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/expense_categories`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          space: spaceIri,
          name: name.trim(),
          unitAmount: unitPrice.trim()
            ? Math.round((parseFloat(unitPrice) || 0) * 100)
            : null,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data["hydra:description"] || data.detail || "Failed to add.");
      }
      setName("");
      setUnitPrice("");
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Failed to add the category.");
    } finally {
      setBusy(false);
    }
  };

  const setArchived = async (row: ExpenseCategoryRow, archived: boolean) => {
    setError(null);
    const res = await fetch(`${ENTRYPOINT}${row["@id"]}`, {
      method: "PATCH",
      credentials: "include",
      headers: { "Content-Type": "application/merge-patch+json" },
      body: JSON.stringify({ archived }),
    });
    if (!res.ok) {
      setError("Failed to update the category.");
      return;
    }
    await load();
  };


  return (
    <Card className="mb-6">
      <CardContent className="pt-6 space-y-4">
        <div>
          <h2 className="font-semibold">Expense categories</h2>
          <p className="text-sm text-muted-foreground">
            The list members pick from when logging expenses. Add a unit price
            for mileage-style categories (amount = units × price).
          </p>
        </div>

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {categories.length > 0 && (
          <ul className="divide-y divide-border rounded-md border">
            {categories.map((row) => (
              <li key={row["@id"]} className="flex items-center gap-3 px-3 py-2 text-sm">
                <span className={row.archived ? "text-muted-foreground line-through" : "font-medium"}>
                  {row.name}
                </span>
                {row.unitAmount != null && (
                  <span className="text-xs text-muted-foreground">
                    {(row.unitAmount / 100).toFixed(2)}/unit
                  </span>
                )}
                <span className="ml-auto">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => void setArchived(row, !row.archived)}
                  >
                    {row.archived ? "Restore" : "Archive"}
                  </Button>
                </span>
              </li>
            ))}
          </ul>
        )}

        <div className="flex flex-wrap items-end gap-2">
          <Input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Category name"
            maxLength={80}
            className="w-56"
            aria-label="Category name"
          />
          <Input
            type="number"
            step="0.01"
            min="0"
            value={unitPrice}
            onChange={(e) => setUnitPrice(e.target.value)}
            placeholder="Unit price (optional)"
            className="w-44"
            aria-label="Unit price"
          />
          <Button size="sm" onClick={() => void add()} disabled={busy || !name.trim()}>
            <Plus className="h-4 w-4" /> Add
          </Button>
        </div>
      </CardContent>
    </Card>
  );
};

export default ExpenseCategoriesCard;
