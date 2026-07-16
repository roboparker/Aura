/** Wire shape for `/expenses` (#650). */
export interface Expense {
  "@id": string;
  id: string;
  space: string;
  engagement: string | null;
  user: string;
  spentOn: string;
  category: string | null;
  amount: number;
  currency: string | null;
  description: string | null;
  billable: boolean;
  receipt: string | null;
  billedAt: string | null;
  createdAt: string;
  updatedAt: string | null;
}
