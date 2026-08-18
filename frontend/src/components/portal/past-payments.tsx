"use client";

import { useCallback, useState } from "react";
import { useRouter } from "next/navigation";
import {
  ApiError,
  formatPeso,
  getRecentPayments,
  type PortalPayment,
} from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { ChevronDown, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";

type State =
  | { status: "idle" }
  | { status: "loading" }
  | { status: "error"; message: string }
  | { status: "ready"; payments: PortalPayment[] };

function formatDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

export function paymentMethodLabel(payment: PortalPayment): string {
  const channel = payment.channel?.toLowerCase();
  if (channel === "gcash") return "GCash";
  if (channel === "qrph") return "QR Ph";
  if (channel === "card") return "Card";
  if (channel === "google_pay_card" || channel === "googlepay") return "Google Pay";
  if (payment.method === "cash") return "Cash / office";
  if (payment.method === "paymongo") return "PayMongo";
  const raw = String(payment.method ?? "").trim();
  if (!raw) return "—";
  return raw
    .split(/[_\-]/)
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");
}

export function PastPayments() {
  const router = useRouter();
  const { logout } = useAuth();
  const [open, setOpen] = useState(false);
  const [state, setState] = useState<State>({ status: "idle" });

  const load = useCallback(() => {
    setState({ status: "loading" });
    getRecentPayments()
      .then((payments) => setState({ status: "ready", payments }))
      .catch((err) => {
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
  }, [logout, router]);

  const toggle = () => {
    const next = !open;
    setOpen(next);
    if (next && state.status === "idle") {
      load();
    }
  };

  return (
    <section className="rounded-xl border border-border bg-card shadow-sm">
      <button
        type="button"
        onClick={toggle}
        data-testid="past-payments-toggle"
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-3 px-5 py-4 text-left transition-colors hover:bg-muted/40"
      >
        <span className="text-sm font-semibold">Past payments</span>
        <ChevronDown
          className={cn(
            "size-4 text-muted-foreground transition-transform",
            open && "rotate-180"
          )}
        />
      </button>

      {open && state.status === "loading" && (
        <div className="flex items-center gap-3 border-t border-border px-5 py-6 text-sm text-muted-foreground" aria-busy="true">
          <Loader2 className="size-4 animate-spin" />
          Loading your recent payments…
        </div>
      )}

      {open && state.status === "error" && (
        <div className="flex flex-col items-center gap-3 border-t border-border px-5 py-6 text-center">
          <p className="text-sm font-semibold">Couldn&apos;t load past payments</p>
          <p className="text-xs text-muted-foreground">{state.message}</p>
          <button
            type="button"
            onClick={load}
            className="rounded-md border border-border bg-primary px-5 py-1.5 text-xs font-semibold uppercase tracking-wider text-primary-foreground hover:bg-primary/90"
          >
            Try again
          </button>
        </div>
      )}

      {open && state.status === "ready" && state.payments.length === 0 && (
        <p className="border-t border-border px-5 py-6 text-sm text-muted-foreground">
          No past payments yet.
        </p>
      )}

      {open && state.status === "ready" && state.payments.length > 0 && (
        <ul data-testid="past-payments-list" className="divide-y divide-border border-t border-border">
          {state.payments.map((payment) => (
            <li
              key={payment.id}
              className="flex items-center justify-between gap-3 px-5 py-3.5"
            >
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">
                  {payment.invoice_number}
                </p>
                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                  {payment.service_connection.account_number} ·{" "}
                  {formatDate(payment.paid_at)}
                </p>
              </div>
              <div className="shrink-0 text-right">
                <p className="text-sm font-bold">
                  {formatPeso(payment.amount)}
                </p>
                <p className="mt-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {paymentMethodLabel(payment)}
                </p>
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
