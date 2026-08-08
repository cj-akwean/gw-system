"use client";

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import {
  clearPendingInvoice,
  readPendingInvoice,
  type PendingInvoice,
} from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { PaymentMethodScreen } from "@/components/portal/payment-method";
import { Loader2 } from "lucide-react";

export default function PayPage() {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-screen w-full items-center justify-center">
          <div
            className="flex items-center gap-3 rounded-xl border border-border bg-card p-4 text-sm text-muted-foreground"
            role="status"
          >
            <Loader2 className="size-4 animate-spin" />
            Loading payment status…
          </div>
        </div>
      }
    >
      <PayPageContent />
    </Suspense>
  );
}

function PayPageContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { isAuthenticated, ready } = useAuth();

  const idParam = searchParams.get("id");
  const fromParam = searchParams.get("from");
  // PayMongo appends its own params on redirect returns (per the PayMongo
  // docs: "Extract the payment_intent_id from the query parameters") — our
  // own id/from may or may not survive.
  const paymentIntentParam = searchParams.get("payment_intent_id");
  const paymentStatusParam = searchParams.get("status");

  // The redirect return can land in a NEW tab (observed for the card 3DS
  // flow), where sessionStorage is empty — the pending record is read from
  // sessionStorage OR localStorage, whichever carries it. Read once at mount
  // (the URL is committed by the time the page renders); the marker expires
  // after an hour and is not cleared on read, so a StrictMode remount cannot
  // lose the recovery.
  const [pending] = useState<PendingInvoice | null>(() =>
    idParam === null && typeof window !== "undefined"
      ? readPendingInvoice()
      : null
  );

  useEffect(() => {
    if (idParam !== null) {
      // A visit that carries its own id is not a return — drop any pending
      // marker left behind by an earlier, interrupted payment.
      clearPendingInvoice();
    }
  }, [idParam]);

  const invoiceId = idParam ?? pending?.invoiceId ?? "";
  const paymentIntentId = paymentIntentParam ?? pending?.paymentIntentId ?? null;
  const returnedFromRedirect =
    fromParam === "redirect" ||
    fromParam === "gcash" ||
    paymentIntentParam !== null ||
    paymentStatusParam !== null ||
    pending !== null;

  useEffect(() => {
    if (ready && !isAuthenticated) {
      router.replace("/auth");
    }
  }, [ready, isAuthenticated, router]);

  if (!ready || !isAuthenticated) {
    return null;
  }

  return (
    <PaymentMethodScreen
      invoiceId={invoiceId}
      paymentIntentId={paymentIntentId}
      returnedFromRedirect={returnedFromRedirect}
    />
  );
}
