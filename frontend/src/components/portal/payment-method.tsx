"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import Image from "next/image";
import {
  ApiError,
  buildReturnUrl,
  clearPendingInvoice,
  formatPeso,
  getInvoice,
  getInvoices,
  resolveIntentStatus,
  startPayment,
  writePendingInvoice,
  type PortalInvoice,
} from "@/lib/api";
import { attachPaymentMethod, createPaymentMethod } from "@/lib/paymongo";
import { CardForm, type CardFormHandle, type CardPayload } from "@/components/portal/card-form";
import { useCountdown } from "@/hooks/use-countdown";
import { formatRemaining } from "@/lib/countdown";
import { useAuth } from "@/lib/auth-context";
import { DashboardHeader } from "@/components/portal/dashboard-header";
import { InfoTip } from "@/components/ui/info-tip";
import { SwipeButton } from "@/components/ui/swipe-button";
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
  | { status: "failed" }
  | { status: "unconfirmed" }
  // No id, no intent, no pending record — the return could not be tied to any
  // payment. Never a definitive negative, and never an endless spinner.
  | { status: "context-missing" }
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
      testUrl: string | null;
    }
  | { phase: "error"; message: string; flow: "qrph" | "gcash" | "card" };

const QR_STORAGE_PREFIX = "gw-qr:";
const POLL_INTERVAL_MS = 15_000;
const CONFIRMING_POLL_MS = 5_000;
const E_WALLET_MAX_TOTAL = 100_000;

interface StoredQr {
  intentId: string;
  imageUrl: string;
  deadline: number | null;
  testUrl: string | null;
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
    return {
      intentId: parsed.intentId,
      imageUrl: parsed.imageUrl,
      deadline: parsed.deadline,
      testUrl: typeof parsed.testUrl === "string" ? parsed.testUrl : null,
    };
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
  paymentIntentId = null,
  returnedFromRedirect = false,
}: {
  invoiceId: string;
  paymentIntentId?: string | null;
  returnedFromRedirect?: boolean;
}) {
  const router = useRouter();
  const { user, logout } = useAuth();
  // An empty visit (no id, no intent) cannot be tied to any payment — show
  // the context-missing state, never a definitive "not available" or an
  // endless spinner.
  const [screen, setScreen] = useState<Screen>(() =>
    invoiceId === "" && paymentIntentId === null
      ? { status: "context-missing" }
      : { status: "loading" }
  );
  const [qr, setQr] = useState<QrState>({ phase: "idle" });
  const [attempt, setAttempt] = useState(0);
  // Success feedback is an overlay, never a screen swap — the pay screen stays
  // visible underneath (no white flash). confirming: PayMongo says the payment
  // succeeded but the webhook has not credited the invoice yet.
  const [paymentResult, setPaymentResult] = useState<{
    status: "paid";
    confirming: boolean;
  } | null>(null);
  // Consecutive failed confirming-polls — after 2+, the modal hints that the
  // connection is having trouble; it never flips the outcome on network errors.
  const [confirmingNetworkFailures, setConfirmingNetworkFailures] = useState(0);
  const [step, setStep] = useState<"method" | "review">("method");
  const [selectedMethod, setSelectedMethod] = useState<"qrph" | "gcash" | "card" | null>(null);
  // When the user clicks "Simulate payment (test)" on a QR Ph code, we open
  // PayMongo's test URL in a new tab and poll aggressively (2s) until the
  // webhook credits the invoice — matching the instant feedback Card/GCash get.
  const [testPaymentPending, setTestPaymentPending] = useState(false);
  const cardFormRef = useRef<CardFormHandle>(null);
  const invoiceRef = useRef<PortalInvoice | null>(null);
  // Invoice id recovered from the payment-intent status response (paid /
  // confirmed / failed all carry it) — used to poll the confirming state and
  // to rebuild the pay screen after a failed attempt.
  const [resolvedInvoiceId, setResolvedInvoiceId] = useState<number | null>(null);

  // Load the invoice — it must still be in the unpaid list to be payable.
  // When it's gone, it may have just been paid (webhook beat the UI, e.g.
  // returning from the GCash/3DS redirect) — check the invoice's own status.
  //
  // A redirect return with an intent id is AUTHORITATIVE: resolve it first,
  // whether or not the invoice is still in the unpaid list (the webhook may
  // simply not have credited yet). This makes GCash, card 3DS, and the
  // frictionless card share ONE outcome flow — paid → success modal,
  // confirmed/processing → confirming modal, failed → failed panel. (An
  // intent id only ever reaches this component from the return URL or the
  // pending record — both are return evidence.)
  useEffect(() => {
    let cancelled = false;

    if (paymentIntentId !== null) {
      resolveIntentStatus(paymentIntentId)
        .then((res) => {
          if (cancelled) return;
          if (res.invoice_id != null) {
            setResolvedInvoiceId(res.invoice_id);
          }
          if (res.status === "paid") {
            clearPendingInvoice();
            setPaymentResult({ status: "paid", confirming: false });
            return;
          }
          if (res.status === "confirmed" || res.status === "processing") {
            // Money moved on PayMongo's side; the webhook credit may lag.
            setPaymentResult({ status: "paid", confirming: true });
            return;
          }
          if (res.status === "failed") {
            setScreen({ status: "failed" });
            return;
          }
          // unknown — keep checking, the webhook may still land.
          setScreen({ status: "unconfirmed" });
        })
        .catch((err) => {
          if (cancelled) return;
          const apiErr = err as ApiError;
          if (apiErr.status === 401) {
            logout().then(() => router.replace("/auth"));
            return;
          }
          // Transient failure or a foreign intent — never a definitive
          // answer from a guess.
          setScreen({ status: "unconfirmed" });
        });
      return () => {
        cancelled = true;
      };
    }

    if (invoiceId === "") {
      // No bill selected and no intent to resolve — nothing to fetch. The
      // screen already renders the context-missing panel (lazy-initialized);
      // it is never a definitive "not available".
      return;
    }

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
              clearPendingInvoice();
              setPaymentResult({ status: "paid", confirming: false });
              return;
            }
            // Unrecognized or inconsistent shape (e.g. raced the webhook) —
            // never a definitive answer from a guess.
            setScreen({ status: "unconfirmed" });
          })
          .catch((err2) => {
            if (cancelled) return;
            const apiErr2 = err2 as ApiError;
            if (apiErr2.status === 401) {
              logout().then(() => router.replace("/auth"));
              return;
            }
            if (apiErr2.status === 403 || apiErr2.status === 404) {
              // Definitive: not yours, or it doesn't exist.
              setScreen({ status: "not-payable" });
              return;
            }
            // Transient failure (network / 429 / 5xx) — keep checking, the
            // webhook may still be landing.
            setScreen({ status: "unconfirmed" });
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
  }, [invoiceId, paymentIntentId, attempt, logout, router]);

  // Poll for payment completion while the screen is open: once the invoice
  // leaves the unpaid list, the webhook has confirmed the payment. The
  // unconfirmed state (post-redirect with a failed probe) keeps polling until
  // the webhook lands and the invoice reports paid.
  const confirmingPaid = paymentResult?.status === "paid" && paymentResult.confirming;

  // Success feedback is an explicit modal — nothing auto-navigates; the user
  // dismisses it with the OK button so they never miss the result.

  useEffect(() => {
    if (screen.status === "ready") {
      const check = () => {
        getInvoices()
          .then((invoices) => {
            const stillUnpaid = invoices.some(
              (inv) => String(inv.id) === String(invoiceId)
            );
            if (!stillUnpaid && invoiceRef.current) {
              clearStoredQr(String(invoiceId));
              setTestPaymentPending(false);
              setPaymentResult({ status: "paid", confirming: false });
            }
          })
          .catch((err) => {
            const apiErr = err as ApiError;
            if (apiErr.status === 401) {
              logout().then(() => router.replace("/auth"));
            }
          });
      };

      // When the user clicks "Simulate payment (test)", poll at 2 s so the
      // success modal appears almost immediately after the webhook fires.
      // Normal idle polling stays at POLL_INTERVAL_MS (15 s).
      const interval = testPaymentPending ? 2_000 : POLL_INTERVAL_MS;
      const id = window.setInterval(check, interval);
      window.addEventListener("focus", check);

      return () => {
        window.clearInterval(id);
        window.removeEventListener("focus", check);
      };
    }

    if (screen.status === "unconfirmed") {
      const check = () => {
        // With a known invoice, probe it directly; on an intent-only return
        // re-resolve the intent — independent of the list call, so one
        // failed request can never wedge the retry.
        const probe =
          invoiceId !== ""
            ? getInvoice(invoiceId).then((inv) => ({ status: inv.status }))
            : paymentIntentId !== null
              ? resolveIntentStatus(paymentIntentId)
              : Promise.resolve({ status: "unknown" as const });

        probe
          .then((res) => {
            if (res.status === "paid") {
              setPaymentResult({ status: "paid", confirming: false });
              return;
            }
            if (
              (res.status === "unpaid" || res.status === "overdue") &&
              invoiceId !== ""
            ) {
              // It's payable again — reload to rebuild the pay screen.
              setAttempt((n) => n + 1);
            }
          })
          .catch((err) => {
            const apiErr = err as ApiError;
            if (apiErr.status === 401) {
              logout().then(() => router.replace("/auth"));
            }
            // otherwise keep waiting — the webhook is the source of truth
          });
      };

      const id = window.setInterval(check, POLL_INTERVAL_MS);
      window.addEventListener("focus", check);

      return () => {
        window.clearInterval(id);
        window.removeEventListener("focus", check);
      };
    }

    if (confirmingPaid && resolvedInvoiceId !== null) {
      // PayMongo confirmed the charge but the webhook hasn't credited the
      // invoice yet — keep polling until it reports paid, then drop the
      // confirming note. Network failures never flip the outcome — only a
      // fresh counter keeps the hint honest.
      const check = () => {
        getInvoice(resolvedInvoiceId)
          .then((inv) => {
            setConfirmingNetworkFailures(0);
            if (inv.status === "paid") {
              setPaymentResult({ status: "paid", confirming: false });
            }
          })
          .catch((err) => {
            const apiErr = err as ApiError;
            if (apiErr.status === 401) {
              logout().then(() => router.replace("/auth"));
              return;
            }
            setConfirmingNetworkFailures((n) => n + 1);
          });
      };

      const id = window.setInterval(check, CONFIRMING_POLL_MS);
      window.addEventListener("focus", check);

      return () => {
        window.clearInterval(id);
        window.removeEventListener("focus", check);
      };
    }

    return;
  }, [
    screen.status,
    confirmingPaid,
    resolvedInvoiceId,
    invoiceId,
    paymentIntentId,
    testPaymentPending,
    logout,
    router,
  ]);

  const { remaining, expired } = useCountdown(    qr.phase === "active" && qr.deadline !== null ? qr.deadline : null
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
          testUrl: attached.testUrl,
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
        writePendingInvoice(invoiceId, {
          paymentIntentId: info.payment_intent_id,
          method: "gcash",
        });
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
          testUrl: attached.testUrl,
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

  const startCard = useCallback(
    async (payload: CardPayload) => {
      setQr({ phase: "starting" });
      try {
        const info = await startPayment(invoiceId);
        const pm = await createPaymentMethod("card", {
          details: payload.details,
          billing: payload.billing,
        });
        setQr({ phase: "attaching" });

        const attached = await attachPaymentMethod({
          intentId: info.payment_intent_id,
          clientKey: info.client_key,
          paymentMethodId: pm,
          returnUrl: buildReturnUrl(invoiceId),
        });

        if (attached.redirectUrl) {
          // 3DS — the bank authenticates the cardholder; the webhook confirms
          // the payment after they return (never mark paid on redirect).
          writePendingInvoice(invoiceId, {
            paymentIntentId: info.payment_intent_id,
            method: "card",
          });
          window.location.assign(attached.redirectUrl);
          return;
        }

        if (attached.status === "succeeded" || attached.status === "processing") {
          // Payment is moving (no 3DS needed). Persist the intent for refresh
          // recovery and resolve it immediately — the outcome modal family
          // (confirming → success) takes over. qr stays "attaching" (busy)
          // until the outcome lands, so Pay cannot be re-clicked.
          writePendingInvoice(invoiceId, {
            paymentIntentId: info.payment_intent_id,
            method: "card",
          });

          resolveIntentStatus(info.payment_intent_id)
            .then((res) => {
              setQr({ phase: "idle" });
              if (res.status === "paid") {
                clearPendingInvoice();
                setPaymentResult({ status: "paid", confirming: false });
                return;
              }
              if (res.status === "confirmed" || res.status === "processing") {
                if (res.invoice_id != null) {
                  setResolvedInvoiceId(res.invoice_id);
                }
                setPaymentResult({ status: "paid", confirming: true });
                return;
              }
              if (res.status === "failed") {
                setScreen({ status: "failed" });
                return;
              }
              // unknown — keep checking, the webhook may still land.
              setScreen({ status: "unconfirmed" });
            })
            .catch(() => {
              setQr({ phase: "idle" });
              // Transient — the unconfirmed checking state keeps polling.
              setScreen({ status: "unconfirmed" });
            });

          return;
        }

        setQr({
          phase: "error",
          message:
            attached.lastPaymentError ??
            "The card payment wasn't accepted. Please try again.",
          flow: "card",
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
          flow: "card",
        });
      }
    },
    [invoiceId, logout, router]
  );

  // Success feedback overlays the current screen — the pay screen underneath
  // stays visible (no white flash, no page swap). The modals are explicit:
  // nothing auto-navigates, and the confirming modal has NO escape that would
  // strand the user before the success confirmation appears (a "back" click
  // there lost the modal forever — the paid bill has no Pay button to return
  // to it; a refresh re-resolves instead).
    const overlay =
    paymentResult !== null
      ? paymentResult.confirming
        ? <ConfirmingModal
            connectionTrouble={confirmingNetworkFailures >= 2}
          />
        : <SuccessModal onOK={() => router.push("/dashboard")} email={user?.email} />
      : null;

  if (screen.status === "loading") {
    return (
      <>
        <div className="relative min-h-screen w-full" style={{ background: "var(--bg)" }}>
          <div className="relative z-10 mx-auto w-full max-w-md px-6 py-16 md:max-w-4xl lg:max-w-5xl">
            <div className="mx-auto max-w-sm animate-pulse space-y-3" aria-busy="true" role="status">
              <div className="h-6 w-2/3 rounded-md bg-muted" />
              <div className="h-28 rounded-xl border border-border bg-muted/50" />
              <div className="h-28 rounded-xl border border-border bg-muted/50" />
            </div>
          </div>
        </div>
        {overlay}
      </>
    );
  }

  if (screen.status === "error") {
    return (
      <>
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
        {overlay}
      </>
    );
  }

  if (screen.status === "not-payable") {
    return (
      <>
        <PayScreenShell>
          <Panel>
            <AlertCircle className="size-8 text-muted-foreground" />
            <p className="text-base font-semibold">This bill isn&apos;t available for payment right now.</p>
            <p className="text-sm text-muted-foreground">
              {returnedFromRedirect
                ? "If you just completed a payment, it may already be confirmed — your bills list shows it."
                : "It may have just been paid, or it&apos;s not on one of your linked accounts."}
            </p>
            <button
            type="button"
            onClick={() => router.push("/dashboard")}
            data-testid="check-my-bills"
            className="rounded-md border border-border bg-primary px-6 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
          >
            Check my bills
          </button>
        </Panel>
      </PayScreenShell>
        {overlay}
      </>
    );
  }

  if (screen.status === "failed") {
    return (
      <>
        <PayScreenShell>
          <Panel data-testid="failed-panel">
          <AlertCircle className="size-8 text-destructive" />
          <p className="text-base font-semibold">Payment didn&apos;t go through</p>
          <p className="text-sm text-muted-foreground">
            Your card wasn&apos;t charged. You can try again, or use another
            payment method.
          </p>
          <button
            type="button"
            onClick={() => {
              if (resolvedInvoiceId !== null) {
                getInvoice(resolvedInvoiceId)
                  .then((inv) => setScreen({ status: "ready", invoice: inv }))
                  .catch(() => setAttempt((n) => n + 1));
              } else {
                setAttempt((n) => n + 1);
              }
            }}
            data-testid="retry-payment"
            className="rounded-md border border-border bg-primary px-6 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
          >
            Try again
          </button>
          <BackButton onClick={() => router.push("/dashboard")} />
        </Panel>
      </PayScreenShell>
        {overlay}
      </>
    );
  }

  if (screen.status === "unconfirmed") {
    return (
      <>
        <PayScreenShell>
          <Panel data-testid="unconfirmed-panel">
            <Loader2 className="size-8 animate-spin text-muted-foreground" />
            <p className="text-base font-semibold">Checking your payment status…</p>
            <p className="text-sm text-muted-foreground">
              {returnedFromRedirect
                ? "You're back from the payment provider. If you just paid, your confirmation is on its way — this page updates automatically."
                : "We couldn't confirm this bill's status. This page updates automatically."}
            </p>
            <button
              type="button"
              onClick={() => setAttempt((n) => n + 1)}
              data-testid="check-again"
              className="rounded-md border border-border bg-primary px-6 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
            >
              Check again
            </button>
            <BackButton onClick={() => router.push("/dashboard")} />
          </Panel>
        </PayScreenShell>
        {overlay}
      </>
    );
  }

  if (screen.status === "context-missing") {
    return (
      <>
        <PayScreenShell>
          <Panel data-testid="context-missing-panel">
            <AlertCircle className="size-8 text-muted-foreground" />
            <p className="text-base font-semibold">
              We couldn&apos;t identify the payment you returned from.
            </p>
            <p className="text-sm text-muted-foreground">
              Your bills list shows the latest payment status, including paid
              bills and recent payments.
            </p>
            <button
              type="button"
              onClick={() => router.push("/dashboard")}
              data-testid="check-my-bills"
              className="rounded-md border border-border bg-primary px-6 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
            >
              Check my bills
            </button>
          </Panel>
        </PayScreenShell>
        {overlay}
      </>
    );
  }

  if (screen.status !== "ready") {
    return null;
  }

  const invoice = screen.invoice;
  const capExceeded = invoice.total_amount > E_WALLET_MAX_TOTAL;
  const busy = qr.phase === "starting" || qr.phase === "attaching";
  // Once a QR exists (shown or expired), the swipe is locked — re-swiping
  // would fabricate a fresh payment intent each time while the aside restores
  // the same stored QR. Regeneration goes through the dedicated "Get a new QR"
  // button instead.
  const qrLocked = qrActive || qrExpired;
  const ewalletDisabled = capExceeded || busy;

  return (
    <>
      <div className="relative min-h-screen w-full" style={{ background: "var(--bg)" }}>
      <div
        className="pointer-events-none fixed inset-0"
        style={{ background: "var(--glow) no-repeat", filter: "blur(var(--glow-blur))" }}
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
          user={user}
          onLogout={() => logout().then(() => router.push("/"))}
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
                      available. Use Card for this bill.
                    </p>
                  )}
                </div>

                <button
                  type="button"
                  onClick={() => {
                    setSelectedMethod("card");
                    setStep("review");
                  }}
                  disabled={busy}
                  data-testid="method-card-card"
                  className={cn(
                    "flex w-full items-start gap-3 rounded-xl border border-border bg-card p-4 text-left shadow-sm transition-colors",
                    busy ? "opacity-60" : "hover:bg-muted/40"
                  )}
                >
                  <CreditCard className="size-5 shrink-0 text-foreground" />
                  <span className="min-w-0">
                    <span className="block text-sm font-semibold">Card</span>
                    <span className="block text-xs text-muted-foreground">
                      Visa and Mastercard
                    </span>
                  </span>
                  <ChevronRight className="ml-auto size-4 shrink-0 text-muted-foreground" />
                </button>

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
                        <dt className="flex items-center gap-1 text-muted-foreground">
                          Penalty
                          <InfoTip
                            content="2% per month interest on the unpaid balance, applied after the due date."
                            label="What the penalty covers"
                          />
                        </dt>
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
                        {selectedMethod === "gcash"
                          ? "GCash"
                          : selectedMethod === "card"
                            ? "Card (Visa / Mastercard)"
                            : "QR Ph (scan QR)"}
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

                  {selectedMethod === "card" && (
                    <CardForm
                      ref={cardFormRef}
                      userEmail={user?.email}
                      onSubmit={startCard}
                    />
                  )}

                  {qr.phase === "active" && (
                    <p className="text-xs text-muted-foreground">
                      Your QR code is ready — scan it to complete the payment.
                    </p>
                  )}

                  {busy || qrLocked || ewalletDisabled ? (
                    <button
                      type="button"
                      disabled
                      data-testid="pay-now"
                      className="flex w-full items-center justify-center gap-2 rounded-md border border-border bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      {busy ? (
                        <>
                          <Loader2 className="size-4 animate-spin" />
                          {qr.phase === "starting"
                            ? "Starting payment…"
                            : selectedMethod === "card"
                              ? "Processing your card…"
                              : "Generating your QR code…"}
                        </>
                      ) : qrActive ? (
                        <>QR ready — scan to pay</>
                      ) : qrExpired ? (
                        <>QR expired — get a new one</>
                      ) : (
                        <>Pay {formatPeso(invoice.total_amount)}</>
                      )}
                    </button>
                  ) : (
                    <SwipeButton
                      data-testid="pay-now"
                      text={`Swipe to pay ${formatPeso(invoice.total_amount)}`}
                      onSwipeComplete={() => {
                        if (selectedMethod === "card") {
                          cardFormRef.current?.submit();
                        } else if (selectedMethod === "gcash") {
                          startGcash();
                        } else {
                          startQrPh();
                        }
                      }}
                    />
                  )}
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

              {qr.phase === "idle" && selectedMethod !== "card" && (
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
                  <Image
                    src={qr.imageUrl}
                    alt="QR code to scan and pay"
                    data-testid="qr-image"
                    className="h-56 w-56 rounded-lg"
                    unoptimized
                    width={224}
                    height={224}
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
                  {qr.phase === "active" && qr.testUrl !== null && (
                    <button
                      type="button"
                      data-testid="qr-test-simulate"
                      onClick={() => {
                        window.open(qr.testUrl!, "paymongo_test");
                        setTestPaymentPending(true);
                      }}
                      className="rounded-md border border-border bg-muted/40 px-4 py-2 text-xs font-semibold text-foreground transition-colors hover:bg-muted/70"
                    >
                      Simulate payment (test)
                    </button>
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
                    onClick={
                      qr.flow === "card"
                        ? () => setQr({ phase: "idle" })
                        : qr.flow === "gcash"
                          ? startGcash
                          : startQrPh
                    }
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
      {overlay}
    </>
  );
}

function SuccessModal({ onOK, email }: { onOK: () => void; email?: string }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div
        data-testid="success-modal"
        role="dialog"
        aria-modal="true"
        aria-label="Payment received"
        className="flex w-full max-w-sm flex-col items-center gap-4 rounded-xl border border-border bg-card p-6 text-center shadow-xl animate-in fade-in zoom-in-95 duration-300"
      >
        <CheckCircle2 className="size-10 text-emerald-500" />
        <div>
          <p className="text-base font-semibold">Payment received</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Your confirmation and receipt are emailed to {email ?? "you"}.
          </p>
        </div>
        <button
          type="button"
          onClick={onOK}
          data-testid="success-ok"
          autoFocus
          className="w-full rounded-md border border-border bg-primary px-6 py-2.5 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
        >
          OK
        </button>
      </div>
    </div>
  );
}

function ConfirmingModal({ connectionTrouble }: { connectionTrouble?: boolean }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div
        data-testid="confirming-modal"
        role="dialog"
        aria-modal="true"
        aria-label="Payment confirmed"
        className="flex w-full max-w-sm flex-col items-center gap-4 rounded-xl border border-border bg-card p-6 text-center shadow-xl animate-in fade-in zoom-in-95 duration-300"
      >
        <Loader2 className="size-8 animate-spin text-muted-foreground" />
        <div>
          <p className="text-base font-semibold">Payment confirmed with your provider</p>
          <p className="mt-1 text-sm text-muted-foreground">
            We&apos;re updating your account — your receipt will be emailed
            shortly.
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            This usually takes a few seconds.
          </p>
        </div>
        {connectionTrouble && (
          <p data-testid="connection-trouble" className="text-xs font-medium text-muted-foreground">
            Having trouble reaching the server — retrying…
          </p>
        )}
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
