import { currencyFractionDigits } from "@/lib/currencies";

/** Wire shapes + helpers for `/clients` and `/invoices` (#445). */

export interface Client {
  "@id": string;
  id: string;
  space: string;
  name: string;
  email: string | null;
  address: string | null;
  currency: string | null;
  defaultRateAmount: number | null;
  notes: string | null;
  createdAt: string;
  updatedAt: string | null;
}

/** Client as embedded on an invoice (readableLink). */
export interface InvoiceClient {
  "@id": string;
  id: string;
  name: string;
}

export interface InvoiceLineItem {
  "@id"?: string;
  id?: string;
  description: string;
  quantity: number;
  unitAmount: number;
  amount?: number;
  position?: number;
}

export type InvoiceStatus = "draft" | "sent" | "paid" | "overdue" | "void";

export type DiscountType = "percent" | "fixed";

export interface InvoicePayment {
  id: string;
  amount: number;
  paidOn: string;
  method: string | null;
  note: string | null;
  createdAt: string;
}

export interface Invoice {
  "@id": string;
  id: string;
  space: string;
  client: InvoiceClient | string;
  number: string | null;
  status: InvoiceStatus;
  issueDate: string | null;
  dueDate: string | null;
  currency: string;
  taxRate: number;
  notes: string | null;
  discountType: DiscountType | null;
  discountValue: number | null;
  discountAmount: number;
  lineItems: InvoiceLineItem[];
  payments: InvoicePayment[];
  amountPaid: number;
  balanceDue: number;
  subtotal: number;
  taxAmount: number;
  total: number;
  recurrenceFrequency: "weekly" | "monthly" | "yearly" | null;
  recurrenceInterval: number | null;
  nextIssueDate: string | null;
  remindersEnabled: boolean;
  remindersSentAt: string[] | null;
  sentAt: string | null;
  paidAt: string | null;
  createdAt: string;
  updatedAt: string | null;
}

export const STATUS_META: Record<InvoiceStatus, { label: string; badgeClass: string }> = {
  draft: { label: "Draft", badgeClass: "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300" },
  sent: { label: "Sent", badgeClass: "bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300" },
  paid: { label: "Paid", badgeClass: "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300" },
  overdue: { label: "Overdue", badgeClass: "bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300" },
  void: { label: "Void", badgeClass: "bg-slate-100 text-slate-500 line-through dark:bg-slate-800" },
};

/** Format a minor-units amount as currency (e.g. 10000, "USD" -> "$100.00"). */
export const formatMoney = (minor: number, currency: string): string => {
  const digits = currencyFractionDigits(currency);
  try {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency,
      minimumFractionDigits: digits,
      maximumFractionDigits: digits,
    }).format(minor / 10 ** digits);
  } catch {
    return `${(minor / 10 ** digits).toFixed(digits)} ${currency}`;
  }
};

export const clientName = (client: InvoiceClient | string): string =>
  typeof client === "string" ? "" : client.name;
