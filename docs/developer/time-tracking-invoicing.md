# Time tracking &amp; invoicing

Madori's agency-style billing stack: track billable time and expenses against a
client's project, then turn unbilled work into invoices (and quotes into
estimates), collect payment online, and reconcile against retainers. The model
mirrors Harvest — see the naming glossary in
[`aura-board-engagement-rename`](../../CLAUDE.md) for the term mapping (Harvest
"Task" = our **Service**).

## The billing chain

```
Client → Project → Service (billing rate) → TimeEntry → Invoice
                                              Expense  ↗
```

- **Client** (`App\Entity\Client`, `/clients`) — the company you bill. Carries
  currency, address, and a default rate. Full CRUD incl. `Patch` (edit
  name/email/address/currency/default rate).
- **Project** (`App\Entity\Project`, `/projects`) — the billable unit time is
  tracked against; belongs to a client, pins the currency, and is what invoices
  are generated from. Carries an optional **budget** (hours or fees) with
  threshold alerts.
- **Service** (`App\Entity\Service`, `/services`) — a named line of billable
  work inside a project (e.g. "Development") that fixes the **`billingRate`**.
- **TimeEntry** (`App\Entity\TimeEntry`, `/time_entries`) — hours logged against
  a project + service. Snapshots the rate at creation (`rateAmount`) so later
  rate changes don't rewrite history. Supports a live timer (no `endedAt` yet).
- **Expense** (`App\Entity\Expense`, `/expenses`) — a billable (or internal)
  cost against a project, filed under an **ExpenseCategory**
  (`/expense_categories`, supports a per-unit price for mileage-style entries),
  with an optional receipt image that rides onto the invoice PDF.

### Money out

- **Invoice** (`App\Entity\Invoice`, `/invoices`) + **InvoiceLineItem** +
  **InvoicePayment**. Generated from unbilled time/expenses; supports discounts
  (percent or fixed), per-line and invoice-level tax, and partial payments. A
  draft is editable; issuing/sending freezes it. Branding (logo, terms, number
  prefix) comes from space settings and renders in
  `templates/invoice/pdf.html.twig`.
- **Estimate** (`App\Entity\Estimate`, `/estimates`) — a quote sent before work;
  the client accepts/declines, then it **converts** to an invoice.
- **RetainerTransaction** — a pre-paid client balance (deposit/draw ledger) that
  invoices draw down against.

### Rates

- **Billing rate** — `Service.billingRate`, what the client is charged/hour.
- **Cost rate** — `SpaceMembership.costRateAmount`, what a person costs; powers
  the profitability report.
- **Per-person rate** — `ProjectUserRate.rateAmount`, a rate override for a
  specific member on a specific project.

## Endpoints

Standard API-Platform CRUD lives on each entity above (`/clients`,
`/projects`, `/services`, `/time_entries`, `/invoices`, `/estimates`,
`/expenses`, `/expense_categories`). The stateful transitions are custom
controllers:

| Route | Controller | Purpose |
|---|---|---|
| `POST /invoices/from-time-entries` | `InvoiceFromTimeController` | Preview + generate an invoice from selected unbilled time/expenses |
| `POST /invoices/{id}/issue` | `InvoiceActionController` | Assign a number + issue date; freezes the draft |
| `POST /invoices/{id}/send` | `InvoiceActionController` | Mint a fresh public token + email the client an `/i/{token}` link |
| `POST /invoices/{id}/mark-paid` / `payments` | `InvoiceActionController` | Record a payment / full-paid |
| `POST /invoices/{id}/void` | `InvoiceActionController` | Void an issued invoice |
| `GET /invoices/{id}/pdf` | (PDF renderer) | Streamed invoice PDF |
| `POST /estimates/{id}/send` / `convert` | `EstimateActionController` | Send a quote / convert an accepted one to an invoice |
| `POST /clients/{id}/portal-link` | `ClientPortalController` | Mint a client-portal token |
| `GET /portal/{token}` (+ `/invoices/{id}/pay`, `/estimates/{id}/accept\|decline`) | `ClientPortalController` | Public client portal: view + pay invoices, accept/decline estimates |

Public, tokenised (unauthenticated) surfaces: `/i/{token}` (pay one invoice),
`/e/{token}` (view/accept one estimate), `/portal/{token}` (the whole client
portal). Tokens are sha256-hashed like password-reset tokens — the plaintext
only ever leaves in the email/link.

## Permission model

Billing rides the space-role system (`App\Security\Permission\SpacePermission`).
The **`invoices`** category (clients + invoices + estimates + expenses +
retainers ride it) is **admin-reserved** by default — a plain member can't see
or manage billing until an admin grants a role that includes it. Time tracking
itself is member-level: members track their own time, and a **billed** entry is
frozen (create/edit/delete 422) once it lands on an invoice. A
[**timesheet approval**](./task-organization.md) (`App\Entity\TimesheetApproval`)
freezes a whole submitted week the same way until an admin approves/rejects it.

## Online payment: the gateway registry

Invoice payment is provider-agnostic behind
`App\Billing\Payment\PaymentGatewayInterface` + `PaymentGatewayRegistry`
(mirrors `CalendarClientRegistry`). Each provider is one tagged class:

- `StripePaymentGateway` — Stripe Checkout over Symfony HttpClient (no SDK),
  env-gated on the Stripe keys; the webhook marks the invoice paid.
- PayPal drops in as a second tagged class (`app.payment_gateway`) — tracked in
  #597, interface + registry are ready.

`PaymentGatewayRegistry::configuredKeys()` reports which providers are live; the
public `/i/{token}` pay page offers whichever are configured.

> Note: this is **client-facing** invoice payment (you collecting from your
> clients) — entirely separate from the **subscription billing** that charges
> for Madori itself (`App\Controller\BillingController`, `/me/billing`,
> `docs/developer/billing.md`).

## Recurring &amp; overdue jobs

Both run on the shared worker via `App\Scheduler\MainScheduleProvider` (no system
cron):

- **Recurring invoices** — a template invoice sets `recurrenceFrequency`
  (weekly/monthly/yearly) × `recurrenceInterval` + a `nextIssueDate`. The daily
  `SpawnRecurringInvoices` job → `App\Service\RecurringInvoiceSpawner::spawnDue()`
  clones each due template into a fresh draft (client/currency/tax/notes/line
  items, **not** the recurrence or time-entry links) and advances the template.
  **End conditions (#598):** set at most one of `recurrenceEndDate` ("until": stop
  once the advanced next issue date passes it) or `recurrenceCount` ("after N
  invoices": decremented each spawn). When the condition is met,
  `Invoice::endRecurrence()` clears the recurrence so the template drops out of
  the sweep and reads as a plain invoice. No end condition = recurs forever.
- **Overdue invoices** — the daily `MarkOverdueInvoices` job
  (`InvoiceRepository::markOverdue`) flips sent invoices past their due date to
  `overdue` and emails the client a late-payment reminder (#649). Because
  `send` only stores the token hash, the reminder **mints a fresh token** so the
  `/i/{token}` link works.

Both jobs also back console commands for manual runs, and `when@test` runs
Messenger inline so the spawn/overdue logic is exercised synchronously in tests.

## Tests

`api/tests/Api/ClientInvoiceTest.php` covers the invoice lifecycle: generation
from time, issue/send, PDF, discounts + partial payments, per-line tax, the
public pay → webhook → paid flow, recurring spawn, and the recurrence end
conditions. Estimates, expenses, budgets, retainers, and the client portal have
their own suites under `api/tests/Api/`.
