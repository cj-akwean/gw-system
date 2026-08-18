"use client";

import { useEffect, useRef, useState } from "react";
import { publicKey } from "@/lib/paymongo";

const GOOGLE_PAY_SCRIPT_URL = "https://pay.google.com/gp/p/ui/pay.js";
const GOOGLE_PAY_LOAD_TIMEOUT_MS = 8_000;

type ButtonState = "loading" | "ready" | "unavailable";

let payJsPromise: Promise<boolean> | null = null;

/**
 * Injects Google Pay's script once (module-level promise cache) and resolves
 * when `window.google.payments.api` exists. Resolves synchronously when the
 * API is already present — which also makes the component unit-testable by
 * pre-stubbing the global before mount. Rejects on script error or timeout.
 */
function loadPayJs(): Promise<boolean> {
  if (typeof window !== "undefined" && window.google?.payments?.api) {
    return Promise.resolve(true);
  }
  if (payJsPromise !== null) {
    return payJsPromise;
  }

  payJsPromise = new Promise<boolean>((resolve, reject) => {
    const script = document.createElement("script");
    script.src = GOOGLE_PAY_SCRIPT_URL;
    script.async = true;

    let done = false;
    const finish = () => {
      if (done) return;
      done = true;
      window.clearTimeout(timer);
      resolve(window.google?.payments?.api !== undefined);
    };
    const fail = (err: Error) => {
      if (done) return;
      done = true;
      window.clearTimeout(timer);
      reject(err);
    };
    const timer = window.setTimeout(
      () => fail(new Error("Google Pay script took too long to load.")),
      GOOGLE_PAY_LOAD_TIMEOUT_MS
    );

    script.addEventListener("load", finish);
    script.addEventListener("error", () =>
      fail(new Error("Google Pay script failed to load."))
    );
    document.head.appendChild(script);
  });

  return payJsPromise;
}

function buildPaymentMethod(key: string): google.payments.api.PaymentMethod {
  return {
    type: "CARD",
    parameters: {
      allowedAuthMethods: ["PAN_ONLY"],
      allowedCardNetworks: ["VISA", "MASTERCARD"],
      billingAddressRequired: true,
      billingAddressParameters: { format: "MIN" },
      emailRequired: true,
    },
    tokenizationSpecification: {
      type: "PAYMENT_GATEWAY",
      parameters: {
        // gatewayMerchantId is the PayMongo public key (per PayMongo's
        // docs), NOT the Google merchant id from the Console.
        gateway: "paymongo",
        gatewayMerchantId: key,
      },
    },
  };
}

function buildPaymentDataRequest(opts: {
  key: string;
  test: boolean;
  amount: number;
}): google.payments.api.PaymentDataRequest {
  return {
    apiVersion: 2,
    apiVersionMinor: 0,
    allowedPaymentMethods: [buildPaymentMethod(opts.key)],
    merchantInfo: {
      merchantName: "Guinobatan Waterworks",
      ...(opts.test
        ? { merchantId: "TEST" }
        : process.env.NEXT_PUBLIC_GOOGLE_PAY_MERCHANT_ID
          ? { merchantId: process.env.NEXT_PUBLIC_GOOGLE_PAY_MERCHANT_ID }
          : {}),
    },
    transactionInfo: {
      totalPriceStatus: "FINAL",
      totalPrice: opts.amount.toFixed(2),
      currencyCode: "PHP",
      countryCode: "PH",
    },
  };
}

function isUserCancelled(err: unknown): boolean {
  if (err === null || typeof err !== "object") return false;
  const e = err as { statusCode?: unknown; statusMessage?: unknown };
  // Google's PaymentsErrorStatus: CANCELED = 11.
  return e.statusCode === 11 || e.statusMessage === "CANCELED";
}

function billingName(
  address?: google.payments.api.PaymentData["paymentMethodData"]["info"]["billingAddress"]
): string {
  if (typeof address?.name === "string" && address.name.trim() !== "") {
    return address.name.trim();
  }
  const parts = [
    address?.address1,
    address?.address2,
    address?.locality,
    address?.administrativeArea,
  ].filter((part): part is string => typeof part === "string" && part.trim() !== "");
  if (parts.length > 0) return parts.join(", ");
  return "Google Pay user";
}

function isTestKey(): boolean {
  try {
    return publicKey().startsWith("pk_test_");
  } catch {
    return false;
  }
}

export function GooglePayButton({
  onToken,
  disabled = false,
  amount,
  onSimulate,
}: {
  onToken: (payment: {
    token: string;
    billing: { name: string; email: string };
  }) => void;
  disabled?: boolean;
  amount: number;
  /**
   * Dev-only test harness: fires the payment.paid simulation over HTTP (see
   * simulatePayment in lib/api). Never wired in production — and structurally
   * impossible to surface there because the link additionally requires a
   * pk_test_ key.
   */
  onSimulate?: () => void;
}) {
  const [state, setState] = useState<ButtonState>("loading");
  const [error, setError] = useState<string | null>(null);
  const hostRef = useRef<HTMLDivElement>(null);
  const paymentsRef = useRef<google.payments.api.PaymentsClient | null>(null);
  const startingRef = useRef(false);

  // Refs so the once-created button's onClick always sees current props.
  // Kept in sync in an effect (never during render — react-hooks/refs).
  const onTokenRef = useRef(onToken);
  const disabledRef = useRef(disabled);
  const amountRef = useRef(amount);
  useEffect(() => {
    onTokenRef.current = onToken;
    disabledRef.current = disabled;
    amountRef.current = amount;
  });

  useEffect(() => {
    let cancelled = false;

    const bootstrap = async () => {
      let key: string;
      try {
        key = publicKey();
      } catch {
        // Not configured — never crash the pay screen; the other methods
        // already show their own "not configured" behavior.
        if (!cancelled) setState("unavailable");
        return;
      }

      // Google Pay requires a secure context; localhost qualifies. Anything
      // else (LAN IP / plain HTTP dev URL) is unavailable, not an error.
      if (typeof window === "undefined" || window.isSecureContext === false) {
        if (!cancelled) setState("unavailable");
        return;
      }

      try {
        const apiReady = await loadPayJs();
        if (cancelled) return;
        if (!apiReady || !window.google?.payments?.api) {
          setState("unavailable");
          return;
        }

        const client = new window.google.payments.api.PaymentsClient({
          environment: key.startsWith("pk_test_") ? "TEST" : "PRODUCTION",
        });
        if (cancelled) return;
        paymentsRef.current = client;

        const ready = await client.isReadyToPay({
          apiVersion: 2,
          apiVersionMinor: 0,
          allowedPaymentMethods: [buildPaymentMethod(key)],
        });
        if (cancelled) return;
        setState(ready.resultType === "CAN_PAY" ? "ready" : "unavailable");
      } catch {
        if (!cancelled) setState("unavailable");
      }
    };

    bootstrap();

    return () => {
      cancelled = true;
    };
  }, []);

  const handleClick = () => {
    if (disabledRef.current || startingRef.current) return;
    const client = paymentsRef.current;
    if (!client) return;

    let key: string;
    try {
      key = publicKey();
    } catch {
      setError("Google Pay isn't configured yet. Please try again later.");
      return;
    }

    startingRef.current = true;
    setError(null);
    client
      .loadPaymentData(
        buildPaymentDataRequest({
          key,
          test: key.startsWith("pk_test_"),
          amount: amountRef.current,
        })
      )
      .then((paymentData) => {
        const token = paymentData?.paymentMethodData?.tokenizationData?.token;
        const info = paymentData?.paymentMethodData?.info;
        if (typeof token !== "string" || token === "") {
          setError(
            "Google Pay didn't return a usable payment token. Please try again."
          );
          return;
        }
        onTokenRef.current({
          token,
          billing: {
            name: billingName(info?.billingAddress),
            email: typeof info?.email === "string" ? info.email : "",
          },
        });
      })
      .catch((err: unknown) => {
        // User-sent cancellation is silent; real failures surface inline.
        if (!isUserCancelled(err)) {
          setError("Google Pay couldn't complete the payment. Please try again.");
        }
      })
      .finally(() => {
        startingRef.current = false;
      });
  };

  useEffect(() => {
    if (state !== "ready" || paymentsRef.current === null) return;
    const host = hostRef.current;
    if (!host) return;
    // Clear so a StrictMode remount never stacks duplicate buttons.
    host.replaceChildren();
    const created = paymentsRef.current.createButton({
      onClick: handleClick,
      buttonColor: "black",
      buttonType: "pay",
    });
    host.appendChild(created);
  }, [state]);

  if (state === "unavailable") {
    return (
      <div className="w-full">
        <button
          type="button"
          disabled
          data-testid="google-pay-unavailable"
          className="w-full cursor-not-allowed rounded-md border border-dashed border-border bg-muted/40 px-6 py-3 text-sm font-semibold text-muted-foreground"
        >
          Google Pay isn&apos;t available on this device/browser right now.
        </button>
        {isTestKey() && onSimulate && (
          <button
            type="button"
            data-testid="google-pay-simulate"
            onClick={onSimulate}
            className="mt-3 w-full rounded-md border border-dashed border-border bg-muted/40 px-4 py-2 text-xs font-semibold text-foreground transition-colors hover:bg-muted/70"
          >
            Simulate payment (test)
          </button>
        )}
      </div>
    );
  }

  if (disabled) {
    return (
      <button
        type="button"
        disabled
        data-testid="google-pay-button"
        className="w-full cursor-not-allowed rounded-md border border-border bg-muted/60 px-6 py-3 text-sm font-semibold text-muted-foreground"
      >
        Connecting to Google Pay…
      </button>
    );
  }

  return (
    <div className="w-full">
      <div ref={hostRef} data-testid="google-pay-button" />
      {state === "loading" && (
        <div className="w-full cursor-not-allowed rounded-md border border-border bg-muted/60 px-6 py-3 text-center text-sm font-semibold text-muted-foreground">
          Loading Google Pay…
        </div>
      )}
      {error && (
        <p role="alert" data-testid="google-pay-error" className="mt-2 text-xs text-destructive">
          {error}
        </p>
      )}
      {isTestKey() && onSimulate && (
        <button
          type="button"
          data-testid="google-pay-simulate"
          onClick={onSimulate}
          className="mt-3 w-full rounded-md border border-dashed border-border bg-muted/40 px-4 py-2 text-xs font-semibold text-foreground transition-colors hover:bg-muted/70"
        >
          Simulate payment (test)
        </button>
      )}
    </div>
  );
}