/** Wire shapes for `/estimates` (#652). */

export interface EstimateLineItem {
  "@id"?: string;
  id?: string;
  description: string;
  quantity: number;
  unitAmount: number;
  amount?: number;
  position?: number;
}

export type EstimateStatus = "draft" | "sent" | "accepted" | "declined";

export interface Estimate {
  "@id": string;
  id: string;
  space: string;
  client: { "@id": string; id: string; name: string } | string;
  number: string | null;
  status: EstimateStatus;
  currency: string;
  taxRate: number;
  notes: string | null;
  lineItems: EstimateLineItem[];
  subtotal: number;
  taxAmount: number;
  total: number;
  convertedInvoice: string | null;
  sentAt: string | null;
  decidedAt: string | null;
  createdAt: string;
  updatedAt: string | null;
}

export const ESTIMATE_STATUS_META: Record<EstimateStatus, { label: string; badgeClass: string }> = {
  draft: { label: "Draft", badgeClass: "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300" },
  sent: { label: "Sent", badgeClass: "bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300" },
  accepted: { label: "Accepted", badgeClass: "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300" },
  declined: { label: "Declined", badgeClass: "bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300" },
};

export const estimateClientName = (client: Estimate["client"]): string =>
  typeof client === "string" ? "" : client.name;
