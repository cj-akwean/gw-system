const PAYMONGO_API = "https://api.paymongo.com/v1";

export interface PayMongoAttachResult {
  status: string;
  imageUrl: string | null;
  redirectUrl: string | null;
  expiresAt: string | null;
}

function publicKey(): string {
  const key = process.env.NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY;
  if (!key) {
    throw new Error("Online payment isn't configured on this portal yet.");
  }
  return key;
}

function isDataImageUrl(value: unknown): value is string {
  return typeof value === "string" && value.startsWith("data:image/");
}

async function payMongoError(res: Response): Promise<Error> {
  const body = await res.json().catch(() => null);
  const code = body?.errors?.[0]?.code;
  const detail = body?.errors?.[0]?.detail;
  const message = typeof detail === "string" && detail
    ? detail
    : `Payment gateway request failed (${res.status}).`;
  return new Error(code ? `${message} (${code})` : message);
}

async function payMongoFetch(path: string, attributes: Record<string, unknown>): Promise<Response> {
  const auth = `Basic ${btoa(`${publicKey()}:`)}`;

  let res: Response;
  try {
    res = await fetch(`${PAYMONGO_API}${path}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: auth,
      },
      body: JSON.stringify({ data: { attributes } }),
    });
  } catch {
    throw new Error("Unable to reach the payment gateway. Please try again.");
  }
  return res;
}

export async function createPaymentMethod(
  type: string,
  extra: Record<string, unknown> = {}
): Promise<string> {
  const res = await payMongoFetch("/payment_methods", { type, ...extra });
  if (!res.ok) throw await payMongoError(res);

  const body = await res.json();
  const id = body?.data?.id;
  if (typeof id !== "string" || id === "") {
    throw new Error("The payment gateway returned an invalid response. Please try again.");
  }
  return id;
}

export async function attachPaymentMethod(opts: {
  intentId: string;
  clientKey: string;
  paymentMethodId: string;
  returnUrl?: string;
}): Promise<PayMongoAttachResult> {
  const res = await payMongoFetch(`/payment_intents/${opts.intentId}/attach`, {
    payment_method: opts.paymentMethodId,
    client_key: opts.clientKey,
    ...(opts.returnUrl ? { return_url: opts.returnUrl } : {}),
  });
  if (!res.ok) throw await payMongoError(res);

  const body = await res.json();
  const attrs = body?.data?.attributes;
  const nextAction = attrs?.next_action ?? null;

  // PayMongo's QR Ph attach returns next_action.type = "consume_qr" (the docs
  // describe the shape under next_action.code but never document the type
  // enum value — verified against a live test-mode attach 2026-08-07).
  // "code" is kept as a defensive alias.
  const hasCode =
    nextAction?.type === "consume_qr" || nextAction?.type === "code";

  return {
    status: typeof attrs?.status === "string" ? attrs.status : "",
    imageUrl: hasCode && isDataImageUrl(nextAction.code?.image_url)
      ? nextAction.code.image_url
      : null,
    redirectUrl:
      nextAction?.type === "redirect" && typeof nextAction.redirect?.url === "string"
        ? nextAction.redirect.url
        : null,
    expiresAt:
      typeof nextAction?.code?.expires_at === "string"
        ? nextAction.code.expires_at
        : null,
  };
}
