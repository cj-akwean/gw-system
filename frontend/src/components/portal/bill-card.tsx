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
        "rounded-xl border bg-card p-5 shadow-sm transition-colors",
        overdue ? "border-destructive/40" : "border-border"
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-medium text-muted-foreground">
            {invoice.service_connection.account_number} ·{" "}
            {invoice.service_connection.registered_name}
          </p>
          <h3 className="mt-0.5 truncate text-sm font-semibold">
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

      <dl className="mt-4 space-y-1.5 text-sm">
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

      <div className="mt-4 flex items-center justify-between gap-3 border-t border-border pt-3">
        <span className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
          Total due
        </span>
        <div className="flex items-center gap-3">
          {onPay && (
            <button
              type="button"
              onClick={onPay}
              data-testid={`pay-${invoice.id}`}
              className="rounded-md border border-border bg-primary px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-primary-foreground transition-colors hover:bg-primary/90"
            >
              Pay now
            </button>
          )}
          <span
            className={cn(
              "text-lg font-bold",
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