"use client";

import { useCallback, useEffect, useState } from "react";
import { Droplets } from "lucide-react";
import { formatPeso, getInvoices, ApiError, type PortalInvoice } from "@/lib/api";
import { BillCard } from "@/components/portal/bill-card";
import { PastPayments } from "@/components/portal/past-payments";
import { useAuth } from "@/lib/auth-context";
import { useRouter } from "next/navigation";

type State =
  | { status: "loading" }
  | { status: "error"; message: string }
  | { status: "ready"; invoices: PortalInvoice[] };

export function BillsList() {
  const router = useRouter();
  const { logout } = useAuth();
  const [state, setState] = useState<State>({ status: "loading" });
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let cancelled = false;

    getInvoices()
      .then((invoices) => {
        if (!cancelled) setState({ status: "ready", invoices });
      })
      .catch((err) => {
        if (cancelled) return;
        const apiErr = err as ApiError;
        if (apiErr.status === 401) {
          logout().then(() => router.replace("/auth"));
          return;
        }
        setState({
          status: "error",
          message: apiErr.message ?? "Something went wrong. Please try again.",
        });
      });

    return () => {
      cancelled = true;
    };
  }, [attempt, logout, router]);

  const retry = useCallback(() => {
    setState({ status: "loading" });
    setAttempt((n) => n + 1);
  }, []);

  if (state.status === "loading") {
    return (
      <section className="space-y-4" aria-busy="true" role="status">
        <p className="text-sm text-muted-foreground">Loading your bills…</p>
        <div className="space-y-3">
          {[0, 1, 2].map((i) => (
            <div
              key={i}
              className="h-24 animate-pulse rounded-xl border border-border bg-muted/50"
            />
          ))}
        </div>
      </section>
    );
  }

  if (state.status === "error") {
    return (
      <section className="flex flex-col items-center gap-4 rounded-xl border border-border bg-card p-8 text-center">
        <p className="text-base font-semibold">Couldn&apos;t load your bills</p>
        <p className="text-sm text-muted-foreground">{state.message}</p>
        <button
          type="button"
          onClick={retry}
          className="rounded-md border border-border bg-primary px-6 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
        >
          Try again
        </button>
      </section>
    );
  }

  const invoices = state.invoices;
  const total = invoices.reduce(
    (sum, inv) => sum + (Number.isFinite(inv.total_amount) ? inv.total_amount : 0),
    0
  );

  // Group by connection (account/meter) — the API already sorts overdue-first
  // then by due date, and grouping preserves that order within each group.
  const groups: { key: string; connection: PortalInvoice["service_connection"]; invoices: PortalInvoice[] }[] =
    [];
  for (const inv of invoices) {
    const key = inv.service_connection.account_number;
    const existing = groups.find((g) => g.key === key);
    if (existing) {
      existing.invoices.push(inv);
    } else {
      groups.push({ key, connection: inv.service_connection, invoices: [inv] });
    }
  }

  return (
    <section className="space-y-6">
      <div className="flex items-end justify-between gap-3">
        <div className="min-w-0">
          <h2 className="text-2xl font-bold tracking-tight">My Bills</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            {invoices.length === 0
              ? "You have no unpaid bills."
              : `${invoices.length} unpaid ${
                  invoices.length === 1 ? "bill" : "bills"
                }`}
          </p>
        </div>
        {invoices.length > 0 && (
          <p className="shrink-0 text-xl font-bold tracking-tight tabular-nums">
            {formatPeso(total)}
          </p>
        )}
      </div>

      {invoices.length === 0 ? (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-border bg-card p-10 text-center">
          <p className="text-sm font-medium">You&apos;re all caught up!</p>
          <p className="text-xs text-muted-foreground">
            No unpaid bills on your linked accounts right now.
          </p>
        </div>
      ) : (
        <div className="space-y-6">
          {groups.map((group) => (
            <div key={group.key} data-testid={`connection-${group.key}`} className="space-y-3">
              <div className="flex items-center gap-3 rounded-xl border border-border/70 bg-card/60 px-4 py-3">
                <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                  <Droplets aria-hidden className="size-4 text-primary" />
                </div>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold">
                    {group.connection.account_number} · {group.connection.meter_number}
                  </p>
                  <p className="truncate text-xs text-muted-foreground">
                    {group.connection.registered_name}
                    {group.connection.barangay ? ` · ${group.connection.barangay}` : ""}
                  </p>
                </div>
              </div>
              <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {group.invoices.map((invoice) => (
                  <BillCard
                    key={invoice.id}
                    invoice={invoice}
                    onPay={() => router.push(`/dashboard/pay?id=${invoice.id}`)}
                  />
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}

      <PastPayments />
    </section>
  );
}