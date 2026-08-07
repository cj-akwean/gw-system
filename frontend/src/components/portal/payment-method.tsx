"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import {
  ApiError,
  buildReturnUrl,
  formatPeso,
  getInvoice,
  getInvoices,
  startPayment,
  type PortalInvoice,
} from "@/lib/api";
import { attachPaymentMethod, createPaymentMethod } from "@/lib/paymongo";
import { useCountdown } from "@/hooks/use-countdown";
import { formatRemaining } from "@/lib/countdown";
import { useAuth } from "@/lib/auth-context";
import { DashboardHeader } from "@/components/portal/dashboard-header";
import { cn } from "@/lib/utils";
import {
  AlertCircle,
  ArrowLeft,
  CheckCircle2,
  ChevronRight,
  Clock,
  CreditCard,
  Loader2,
  ScanLine,
  Smartphone,
  Wallet,
} from "lucide-react";

type Screen =
  | { status: "loading" }
  | { status: "error"; message: string }
  | { status: "not-payable" }
  | { status: "paid" }
  | { status: "ready"; invoice: PortalInvoice };

type QrState =
  | { phase: "idle" }
  | { phase: "starting" }
  | { phase: "attaching" }
  | {
      phase: "active";
      imageUrl: string;
      deadline: number | null;
      intentId: string;
    }
  | { phase: "error"; message: string; flow: "qrph" | "gcash" };

const QR_STORAGE_PREFIX = "gw-qr:";
const POLL_INTERVAL_MS = 15_000;
const E_WALLET_MAX_TOTAL = 100_000;

interface StoredQr {
  intentId: string;
  imageUrl: string;
  deadline: number | null;
}

function readStoredQr(intentId: string): StoredQr | null {
  try {
    const raw = window.sessionStorage.getItem(`${QR_STORAGE_PREFIX}${intentId}`);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as StoredQr;
    if (
      typeof parsed.intentId !== "string" ||
      typeof parsed.imageUrl !== "string" ||
      (parsed.deadline !== null && typeof parsed.deadline !== "number")
    ) {
      return null;
    }
    return parsed;
  } catch {
    return null;
  }
}

function writeStoredQr(qr: StoredQr): void {
  try {
    window.sessionStorage.setItem(
      `${QR_STORAGE_PREFIX}${qr.intentId}`,
      JSON.stringify(qr)
    );
  } catch {
    // storage unavailable — the countdown just won't survive a refresh
  }
}

function clearStoredQr(intentId: string): void {
  try {
    window.sessionStorage.removeItem(`${QR_STORAGE_PREFIX}${intentId}`);
  } catch {
    // ignore
  }
}

function isUnauthorized(err: unknown): boolean {
  return err instanceof Error && (err as { status?: unknown }).status === 401;
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

export function PaymentMethodScreen({
  invoiceId,
  returnedFromGcash = false,
}: {
  invoiceId: string;
  returnedFromGcash?: boolean;
}) {
  const router = useRouter();
  const { user, logout } = useAuth();
  const [screen, setScreen] = useState<Screen>({ status: "loading" });
  const [qr, setQr] = useState<QrState>({ phase: "idle" });
  const pendingBanner = returnedFromGcash;
  const [attempt, setAttempt] = useState(0);
  const [step, setStep] = useState<"method" | "review">("method");
  const [selectedMethod, setSelectedMethod] = useState<"qrph" | "gcash" | null>(null);
  const invoiceRef = useRef<PortalInvoice | null>(null);

  // Load the invoice — it must still be in the unpaid list to be payable.
  // When it's gone, it may have just been paid (webhook beat the UI, e.g.
  // returning from the GCash redirect) — check the invoice's own status.
  useEffect(() => {
    let cancelled = false;

    getInvoices()
      .then((invoices) => {
        if (cancelled) return;
        const invoice = invoices.find(
          (inv) => String(inv.id) === String(invoiceId)
        );
        if (invoice) {
          invoiceRef.current = invoice;
          setScreen({ status: "ready", invoice });
          return;
        }

        getInvoice(invoiceId)
          .then((inv) => {
            if (cancelled) return;
            if (inv.status === "paid") {
              setScreen({ status: "paid" });
              return;
            }
            setScreen({ status: "not-payable" });
          })
          .catch((err2) => {
            if (cancelled) return;
            const apiErr2 = err2 as ApiError;
            if (apiErr2.status === 401) {
              logout().then(() => router.replace("/auth"));
              return;
            }
            setScreen({ status: "not-payable" });
          });
      })
      .catch((err) => {
        if (cancelled) return;
        const apiErr = err as ApiError;
        if (apiErr.status === 401) {
          logout().then(() => router.replace("/auth"));
          return;
        }
        setScreen({
          status: "error",
          message: apiErr.message ?? "Something went wrong. Please try again.",
        });
      });

    return () => {
      cancelled = true;
    };
  }, [invoiceId, attempt, logout, router]);

  // Coming back from the GCash redirect? Drop the marker from the URL once
  // read so a refresh doesn't re-show the banner.
  useEffect(() => {
    if (pendingBanner) {
      window.history.replaceState({}, "", window.location.pathname);
    }
  }, [pendingBanner]);

  // Poll for payment completion while the screen is open: once the invoice
  // leaves the unpaid list, the webhook has confirmed the payment.
  useEffect(() => {
    if (screen.status !== "ready") return;

    const check = () => {
      getInvoices()
        .then((invoices) => {
          const stillUnpaid = invoices.some(
            (inv) => String(inv.id) === String(invoiceId)
          );
          if (!stillUnpaid && invoiceRef.current) {
            clearStoredQr(String(invoiceId));
            setScreen({ status: "paid" });
          }
        })
        .catch((err) => {
          const apiErr = err as ApiError;
          if (apiErr.status === 401) {
            logout().then(() => router.replace("/auth"));
          }
        });
    };

    const id = window.setInterval(check, POLL_INTERVAL_MS);
    window.addEventListener("focus", check);

    return () => {
      window.clearInterval(id);
      window.removeEventListener("focus", check);
    };
  }, [screen.status, invoiceId, logout, router]);

  const { remaining, expired } = useCountdown(
    qr.phase === "active" && qr.deadline !== null ? qr.deadline : null
  );

  const qrExpired = qr.phase === "active" && qr.deadline !== null && expired;
  const qrActive = qr.phase === "active" && !qrExpired;

  const startQrPh = useCallback(async () => {
    setQr({ phase: "starting" });
    try {
      const info = await startPayment(invoiceId);
      const stored = readStoredQr(info.payment_intent_id);

      if (stored && stored.deadline !== null && stored.deadline > Date.now()) {
        setQr({ phase: "active", ...stored });
        return;
      }

      const pm = await createPaymentMethod("qrph", {
        expiry_seconds: info.expiry_seconds,
      });
      setQr({ phase: "attaching" });

      const attached = await attachPaymentMethod({
        intentId: info.payment_intent_id,
        clientKey: info.client_key,
        paymentMethodId: pm,
      });

      if (attached.imageUrl) {
        // PayMongo's own expiry moment (RFC3339) is the authoritative
        // deadline; fall back to attach-time + backend expiry_seconds only
        // if it's missing.
        const expiresMs = attached.expiresAt ? Date.parse(attached.expiresAt) : NaN;
        const next: StoredQr = {
          intentId: info.payment_intent_id,
          imageUrl: attached.imageUrl,
          deadline: Number.isFinite(expiresMs)
            ? expiresMs
            : Date.now() + info.expiry_seconds * 1000,
        };
        writeStoredQr(next);
        setQr({ phase: "active", ...next });
      } else {
        setQr({
          phase: "error",
          message: "We couldn't generate a QR code. Please try again.",
          flow: "qrph",
        });
      }
    } catch (err) {
      if (isUnauthorized(err)) {
        logout().then(() => router.replace("/auth"));
        return;
      }
      setQr({
        phase: "error",
        message:
          err instanceof Error
            ? err.message
            : "We couldn't start the payment. Please try again.",
        flow: "qrph",
      });
    }
  }, [invoiceId, logout, router]);

  const startGcash = useCallback(async () => {
    setQr({ phase: "starting" });
    try {
      const info = await startPayment(invoiceId);
      const pm = await createPaymentMethod("gcash");
      setQr({ phase: "attaching" });

      const attached = await attachPaymentMethod({
        intentId: info.payment_intent_id,
        clientKey: info.client_key,
        paymentMethodId: pm,
        returnUrl: buildReturnUrl(invoiceId),
      });

      if (attached.redirectUrl) {
        window.location.assign(attached.redirectUrl);
        return;
      }
      if (attached.imageUrl) {
        // PayMongo returned a code instead of a redirect (unexpected for
        // GCash) — show the QR; use PayMongo's expiry when present, else
        // GCash's 4-hour window means no countdown at all.
        const expiresMs = attached.expiresAt ? Date.parse(attached.expiresAt) : NaN;
        const next: StoredQr = {
          intentId: info.payment_intent_id,
          imageUrl: attached.imageUrl,
          deadline: Number.isFinite(expiresMs) ? expiresMs : null,
        };
        writeStoredQr(next);
        setQr({ phase: "active", ...next });
        return;
      }
      setQr({
        phase: "error",
        message:
          "GCash isn't available for this payment right now. Try the QR code instead.",
        flow: "gcash",
      });
    } catch (err) {
      if (isUnauthorized(err)) {
        logout().then(() => router.replace("/auth"));
        return;
      }
      setQr({
        phase: "error",
        message:
          err instanceof Error
            ? err.message
            : "We couldn't start the payment. Please try again.",
        flow: "gcash",
      });
    }
  }, [invoiceId, logout, router]);

  if (screen.status === "loading") {
    return (
      <div className="relative min-h-screen w-full" style={{ background: "var(--bg)" }}>
        <div className="relative z-10 mx-auto w-full max-w-md px-6 py-16 md:max-w-4xl lg:max-w-5xl">
          <div className="mx-auto max-w-sm animate-pulse space-y-3" aria-busy="true" role="status">
            <div className="h-6 w-2/3 rounded-md bg-muted" />
            <div className="h-28 rounded-xl border border-border bg-muted/50" />
            <div className="h-28 rounded-xl border border-border bg-muted/50" />
          </div>
        </div>
      </div>
    );
  }

  if (screen.status === "error") {
    return (
      <PayScreenShell>
        <Panel>
          <AlertCircle className="size-8 text-destructive" />
          <p className="text-base font-semibold">Couldn&apos;t load this bill</p>
          <p className="text-sm text-muted-foreground">{screen.message}</p>
          <button
            type="button"
            onClick={() => setAttempt((n) => n + 1)}
            className="rounded-md border border-border bg-primary px-6 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
          >
            Try again
          </button>
        </Panel>
      </PayScreenShell>
    );
  }

  if (screen.status === "not-payable") {
    return (
      <PayScreenShell>
        <Panel>
          <AlertCircle className="size-8 text-muted-foreground" />
          <p className="text-base font-semibold">This bill isn&apos;t available for payment right now.</p>
          <p className="text-sm text-muted-foreground">
            It may have just been paid, or it&apos;s not on one of your linked accounts.
          </p>
          <BackButton onClick={() => router.push("/dashboard")} />
        </Panel>
      </PayScreenShell>
    );
  }

  if (screen.status === "paid") {
    return (
      <PayScreenShell>
        <Panel data-testid="paid-panel">
          <CheckCircle2 className="size-10 text-emerald-500" />
          <p className="text-lg font-bold">Payment received</p>
          <p className="text-sm text-muted-foreground">
            Your confirmation and receipt are emailed to {user?.email ?? "you"}.
          </p>
          <BackButton onClick={() => router.push("/dashboard")} />
        </Panel>
      </PayScreenShell>
    );
  }

  const invoice = screen.invoice;
  const capExceeded = invoice.total_amount > E_WALLET_MAX_TOTAL;
  const busy = qr.phase === "starting" || qr.phase === "attaching";
  const ewalletDisabled = capExceeded || busy;

  return (
    <div className="relative min-h-screen w-full" style={{ background: "var(--bg)" }}>
      <div
        className="pointer-events-none fixed inset-0"
        style={{ background: "var(--glow) no-repeat", filter: "blur(80px)" }}
      />
      <div
        className="pointer-events-none fixed inset-0"
        style={{
          backgroundImage:
            "radial-gradient(circle at 1px 1px, var(--dot) 1px, transparent 0)",
          backgroundSize: "20px 20px",
        }}
      />

      <div className="relative z-10 mx-auto flex min-h-screen w-full max-w-md flex-col px-6 pb-12 md:max-w-4xl lg:max-w-5xl">
        <DashboardHeader
          userName={user?.name}
          userEmail={user?.email}
          onLogout={() => logout()}
        />
        <main className="flex-1">
          <button
            type="button"
            onClick={() => router.push("/dashboard")}
            data-testid="back-to-bills"
            className="mb-6 mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
          >
            <ArrowLeft className="size-4" />
            My bills
          </button>

          <div className="grid gap-6 lg:grid-cols-[1fr_22rem] lg:items-start lg:gap-10">
            <section className="space-y-4">
              <div>
                <h1 className="text-2xl font-bold tracking-tight">
                  Pay · {invoice.invoice_number}
                </h1>
                <p className="mt-1 text-sm text-muted-foreground">
                  {invoice.service_connection.account_number} ·{" "}
                  {invoice.service_connection.registered_name}
                </p>
              </div>

              {pendingBanner && (
                <div
                  data-testid="pending-banner"
                  className="flex items-start gap-3 rounded-xl border border-border bg-card p-4 text-sm"
                >
                  <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-muted-foreground" />
                  <p className="text-muted-foreground">
                    Payment in progress — we&apos;re checking with the payment
                    provider. Your receipt will be emailed once it&apos;s
                    confirmed.
                  </p>
                </div>
              )}

              {step === "method" && (
                <div className="space-y-3">
                  <h2 className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                    Payment method
                  </h2>

                <div
                  data-testid="method-card-ewallet"
                  className={cn(
                    "rounded-xl border bg-card p-4 shadow-sm transition-colors",
                    ewalletDisabled ? "border-border opacity-60" : "border-border"
                  )}
                >
                  <div className="flex items-center gap-3">
                    <Wallet className="size-5 shrink-0 text-foreground" />
                    <p className="text-sm font-semibold">E-wallet</p>
                    <span className="ml-auto shrink-0 rounded-full bg-primary/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-primary">
                      Recommended
                    </span>
                  </div>

                  <div className="mt-3 space-y-2">
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedMethod("qrph");
                        setStep("review");
                      }}
                      disabled={ewalletDisabled}
                      data-testid="qr-ph-row"
                      className="flex w-full items-center gap-3 rounded-lg border border-border bg-muted/40 px-3 py-3 text-left transition-colors hover:bg-muted/70 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      <ScanLine className="size-5 shrink-0" />
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-medium">Scan QR · QR Ph</span>
                        <span className="block text-xs text-muted-foreground">
                          Scan with any PH e-wallet or banking app
                        </span>
                      </span>
                      <ChevronRight className="size-4 shrink-0" />
                    </button>

                    <button
                      type="button"
                      onClick={() => {
                        setSelectedMethod("gcash");
                        setStep("review");
                      }}
                      disabled={ewalletDisabled}
                      data-testid="gcash-row"
                      className="flex w-full items-center gap-3 rounded-lg border border-border bg-muted/40 px-3 py-3 text-left transition-colors hover:bg-muted/70 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      <Wallet className="size-5 shrink-0" />
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-medium">Open in GCash</span>
                        <span className="block text-xs text-muted-foreground">
                          Redirects to GCash to finish the payment
                        </span>
                      </span>
                      <ChevronRight className="size-4 shrink-0" />
                    </button>
                  </div>

                  {capExceeded && (
                    <p className="mt-3 text-xs text-muted-foreground">
                      This bill is over ₱100,000, so e-wallet payments aren&apos;t
                      available. Card payments are coming soon.
                    </p>
                  )}
                </div>

                <div
                  data-testid="method-card-card"
                  className="rounded-xl border border-border bg-card p-4 opacity-60 shadow-sm"
                >
                  <div className="flex items-center gap-3">
                    <CreditCard className="size-5 shrink-0 text-muted-foreground" />
                    <p className="text-sm font-semibold text-muted-foreground">Card</p>
                    <span className="ml-auto shrink-0 rounded-full bg-muted px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                      Coming soon
                    </span>
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Visa and Mastercard (coming soon)
                  </p>
                </div>

                <div
                  data-testid="method-card-digital-wallet"
                  className="rounded-xl border border-border bg-card p-4 opacity-60 shadow-sm"
                >
                  <div className="flex items-center gap-3">
                    <Smartphone className="size-5 shrink-0 text-muted-foreground" />
                    <p className="text-sm font-semibold text-muted-foreground">
                      Digital Wallet
                    </p>
                    <span className="ml-auto shrink-0 rounded-full bg-muted px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                      Coming soon
                    </span>
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Google Pay (coming soon)
                  </p>
                </div>
                </div>
              )}

              {step === "review" && selectedMethod !== null && (
                <div
                  data-testid="review-step"
                  className="space-y-4 rounded-xl border border-border bg-card p-5 shadow-sm"
                >
                  <div>
                    <h2 className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                      Review &amp; pay
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      Confirm the amount and payment method.
                    </p>
                  </div>

                  <dl className="space-y-1.5 text-sm">
                    <div className="flex justify-between gap-3">
                      <dt className="text-muted-foreground">Current charges</dt>
                      <dd className="text-right">
                        {formatPeso(invoice.base_amount)}
                      </dd>
                    </div>
                    {invoice.previous_balance > 0 && (
                      <div className="flex justify-between gap-3">
                        <dt className="text-muted-foreground">Arrears</dt>
                        <dd className="text-right">
                          {formatPeso(invoice.previous_balance)}
                        </dd>
                      </div>
                    )}
                    {invoice.penalty_amount > 0 && (
                      <div className="flex justify-between gap-3">
                        <dt className="text-muted-foreground">Penalty</dt>
                        <dd className="text-right">
                          {formatPeso(invoice.penalty_amount)}
                        </dd>
                      </div>
                    )}
                    <div className="flex items-center justify-between gap-3 border-t border-border pt-2.5">
                      <dt className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Total
                      </dt>
                      <dd data-testid="review-total" className="text-lg font-bold">
                        {formatPeso(invoice.total_amount)}
                      </dd>
                    </div>
                  </dl>

                  <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-muted/40 px-3 py-2.5">
                    <div className="min-w-0">
                      <p className="text-xs text-muted-foreground">Paying with</p>
                      <p className="truncate text-sm font-semibold">
                        {selectedMethod === "gcash" ? "GCash" : "QR Ph (scan QR)"}
                      </p>
                    </div>
                    <button
                      type="button"
                      onClick={() => setStep("method")}
                      data-testid="change-method"
                      className="shrink-0 text-xs font-semibold uppercase tracking-wider text-primary underline-offset-2 hover:underline"
                    >
                      Change
                    </button>
                  </div>

                  {qr.phase === "active" && (
                    <p className="text-xs text-muted-foreground">
                      Your QR code is ready — scan it to complete the payment.
                    </p>
                  )}

                  <button
                    type="button"
                    onClick={selectedMethod === "gcash" ? startGcash : startQrPh}
                    disabled={busy || ewalletDisabled}
                    data-testid="pay-now"
                    className="flex w-full items-center justify-center gap-2 rounded-md border border-border bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    {busy ? (
                      <>
                        <Loader2 className="size-4 animate-spin" />
                        {qr.phase === "starting"
                          ? "Starting payment…"
                          : "Generating your QR code…"}
                      </>
                    ) : (
                      <>Pay {formatPeso(invoice.total_amount)}</>
                    )}
                  </button>
                </div>
              )}
            </section>

            <aside className="space-y-4 lg:sticky lg:top-6">
              <div className="rounded-xl border border-border bg-card p-5 shadow-sm">
                <h3 className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                  Bill summary
                </h3>
                <dl className="mt-3 space-y-1.5 text-sm">
                  <div className="flex justify-between gap-3">
                    <dt className="text-muted-foreground">Account</dt>
                    <dd className="text-right">
                      {invoice.service_connection.account_number}
                    </dd>
                  </div>
                  <div className="flex justify-between gap-3">
                    <dt className="text-muted-foreground">Billing period</dt>
                    <dd className="text-right">
                      {formatDate(invoice.billing_period_start)} –{" "}
                      {formatDate(invoice.billing_period_end)}
                    </dd>
                  </div>
                  <div className="flex justify-between gap-3">
                    <dt className="text-muted-foreground">Due date</dt>
                    <dd className="text-right">{formatDate(invoice.due_date)}</dd>
                  </div>
                  <div className="flex items-center justify-between gap-3 border-t border-border pt-2.5">
                    <dt className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      Total due
                    </dt>
                    <dd data-testid="pay-amount" className="text-lg font-bold">
                      {formatPeso(invoice.total_amount)}
                    </dd>
                  </div>
                </dl>
              </div>

              {qr.phase === "idle" && (
                <div className="flex items-center gap-3 rounded-xl border border-dashed border-border bg-card/60 p-4 text-sm text-muted-foreground">
                  <Clock className="size-4 shrink-0" />
                  <p>
                    {step === "review"
                      ? "Review and pay — your QR code will appear here."
                      : "Choose a payment method to continue."}
                  </p>
                </div>
              )}

              {(qr.phase === "starting" || qr.phase === "attaching") && (
                <div
                  className="flex items-center gap-3 rounded-xl border border-border bg-card p-4 text-sm text-muted-foreground"
                  aria-busy="true"
                >
                  <Loader2 className="size-4 shrink-0 animate-spin" />
                  <p>
                    {qr.phase === "starting"
                      ? "Starting your payment…"
                      : "Generating your QR code…"}
                  </p>
                </div>
              )}

              {qrActive && (
                <div
                  data-testid="qr-panel"
                  className="flex flex-col items-center gap-4 rounded-xl border border-border bg-card p-6 text-center shadow-sm"
                >
                  <img
                    src={qr.imageUrl}
                    alt="QR code to scan and pay"
                    data-testid="qr-image"
                    className="h-56 w-56 rounded-lg"
                  />
                  <div className="space-y-1">
                    <p className="text-sm font-semibold">Scan to pay</p>
                    <p className="max-w-56 text-xs text-muted-foreground">
                      Single-use code — scan with any PH e-wallet or banking app
                      (GCash, Maya, GoTyme, and more).
                    </p>
                  </div>
                  {qr.deadline !== null && (
                    <div className="flex items-center gap-2 rounded-full border border-border px-4 py-1.5">
                      <Clock className="size-4 text-muted-foreground" />
                      <span
                        data-testid="countdown"
                        className="font-mono text-lg font-bold tabular-nums"
                      >
                        {formatRemaining(remaining)}
                      </span>
                      <span className="text-xs text-muted-foreground">remaining</span>
                    </div>
                  )}
                </div>
              )}

              {qrExpired && (
                <div
                  data-testid="qr-expired"
                  className="flex flex-col items-center gap-3 rounded-xl border border-border bg-card p-6 text-center shadow-sm"
                >
                  <Clock className="size-8 text-muted-foreground" />
                  <p className="text-sm font-semibold">QR code expired</p>
                  <p className="text-xs text-muted-foreground">
                    It&apos;s no longer valid — generate a fresh one.
                  </p>
                  <button
                    type="button"
                    onClick={startQrPh}
                    data-testid="get-new-qr"
                    className="rounded-md border border-border bg-primary px-6 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                  >
                    Get a new QR
                  </button>
                </div>
              )}

              {qr.phase === "error" && (
                <div className="flex flex-col items-center gap-3 rounded-xl border border-destructive/40 bg-card p-6 text-center">
                  <AlertCircle className="size-8 text-destructive" />
                  <p className="text-sm font-semibold">Payment couldn&apos;t start</p>
                  <p className="text-xs text-muted-foreground">{qr.message}</p>
                  <button
                    type="button"
                    onClick={qr.flow === "gcash" ? startGcash : startQrPh}
                    className="rounded-md border border-border bg-primary px-6 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                  >
                    Try again
                  </button>
                </div>
              )}
            </aside>
          </div>
        </main>
      </div>
    </div>
  );
}

function PayScreenShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="relative min-h-screen w-full" style={{ background: "var(--bg)" }}>
      <div className="relative z-10 mx-auto flex min-h-screen w-full max-w-md flex-col px-6 py-16 md:max-w-4xl lg:max-w-5xl">
        {children}
      </div>
    </div>
  );
}

function Panel({
  children,
  ...rest
}: { children: React.ReactNode } & React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      {...rest}
      className="mx-auto flex max-w-sm flex-col items-center gap-4 rounded-xl border border-border bg-card p-8 text-center shadow-sm"
    >
      {children}
    </div>
  );
}

function BackButton({ onClick }: { onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="rounded-md border border-border bg-primary px-6 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
    >
      Back to my bills
    </button>
  );
}
