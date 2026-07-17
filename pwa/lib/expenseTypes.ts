/** Wire shape for `/expenses` (#650). */
export interface Expense {
  "@id": string;
  id: string;
  space: string;
  engagement: string | null;
  user: string;
  spentOn: string;
  category: string | null;
  /** Managed category IRI (#671); the `category` label mirrors its name. */
  expenseCategory?: string | null;
  amount: number;
  currency: string | null;
  description: string | null;
  billable: boolean;
  receipt: string | null;
  billedAt: string | null;
  createdAt: string;
  updatedAt: string | null;
}

/** Wire shape for `/expense_categories` (#671). */
export interface ExpenseCategoryRow {
  "@id": string;
  id: string;
  space: string;
  name: string;
  /** Minor units per unit for mileage-style categories; null = plain. */
  unitAmount: number | null;
  archived: boolean;
}
