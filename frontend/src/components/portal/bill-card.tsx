"use client";

import { cn } from "@/lib/utils";
import { formatPeso, type PortalInvoice } from "@/lib/api";
import { InfoTip } from "@/components/ui/info-tip";

interface BillCardProps {
  invoice: PortalInvoice;
  onPay?: () => void;
}

function formatDate(iso: string): string {
  const d = new Date(`${iso}T00:00:00`);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

function formatPeriod(invoice: PortalInvoice): string {
  const start = formatDate(invoice.billing_period_start);
  const end = formatDate(invoice.billing_period_end);
  return `${start} – ${end}`;
}

export function BillCard({ invoice, onPay }: BillCardProps) {
  const overdue = invoice.status === "overdue";

  return (
    <li
      data-testid={`invoice-${invoice.id}`}
      className={cn(
        "rounded-2xl border bg-card p-6 shadow-sm transition-colors sm:rounded-xl sm:p-5",
        overdue ? "border-destructive/40" : "border-border"
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-medium text-muted-foreground">
            {invoice.service_connection.account_number} ·{" "}
            {invoice.service_connection.registered_name}
          </p>
          <h3 className="mt-1 truncate text-base font-semibold sm:text-sm">
            {invoice.invoice_number}
          </h3>
        </div>
        <span
          data-testid="status-badge"
          className={cn(
            "shrink-0 rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider",
            overdue
              ? "bg-destructive/10 text-destructive"
              : "bg-muted text-muted-foreground"
          )}
        >
          {invoice.status}
        </span>
      </div>

      <dl className="mt-5 space-y-2 text-sm sm:mt-4 sm:space-y-1.5">
        <div className="flex justify-between gap-3">
          <dt className="text-muted-foreground">Billing period</dt>
          <dd className="text-right">{formatPeriod(invoice)}</dd>
        </div>
        <div className="flex justify-between gap-3">
          <dt className="text-muted-foreground">Due date</dt>
          <dd className="text-right">{formatDate(invoice.due_date)}</dd>
        </div>
        {invoice.previous_balance > 0 && (
          <div className="flex justify-between gap-3">
            <dt className="text-muted-foreground">Previous balance</dt>
            <dd className="text-right">{formatPeso(invoice.previous_balance)}</dd>
          </div>
        )}
        {invoice.penalty_amount > 0 && (
          <div className="flex justify-between gap-3">
            <dt className="flex items-center gap-1 text-muted-foreground">
              Penalty
              <InfoTip
                content="2% per month interest on the unpaid balance, applied after the due date."
                label="What the penalty covers"
              />
            </dt>
            <dd className="text-right">{formatPeso(invoice.penalty_amount)}</dd>
          </div>
        )}
      </dl>

      <div className="mt-5 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t border-border pt-4 sm:mt-4 sm:pt-3">
        <span className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
          Total due
        </span>
        <div className="flex items-center gap-3">
          {onPay && (
            <button
              type="button"
              onClick={onPay}
              data-testid={`pay-${invoice.id}`}
              className="rounded-lg border border-border bg-primary px-5 py-2.5 text-sm font-semibold uppercase tracking-wider text-primary-foreground transition-colors hover:bg-primary/90 sm:rounded-md sm:px-4 sm:py-1.5 sm:text-xs"
            >
              Pay now
            </button>
          )}
          <span
            className={cn(
              "text-2xl font-bold tracking-tight sm:text-lg",
              overdue ? "text-destructive" : "text-foreground"
            )}
          >
            {formatPeso(invoice.total_amount)}
          </span>
        </div>
      </div>
    </li>
  );
}