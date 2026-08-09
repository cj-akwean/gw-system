const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000";

export interface PortalUser {
  id: number;
  name: string | null;
  email: string;
  avatar_id: number | null;
}

interface LoginResponse {
  token: string;
  user: PortalUser;
}

export interface PortalInvoice {
  id: number;
  invoice_number: string;
  billing_period_start: string;
  billing_period_end: string;
  due_date: string;
  previous_balance: number;
  base_amount: number;
  penalty_amount: number;
  total_amount: number;
  status: "unpaid" | "overdue" | "paid";
  service_connection: {
    account_number: string;
    meter_number: string;
    registered_name: string;
    barangay: string | null;
  };
}

function getToken(): string | null {
  try {
    const stored = localStorage.getItem("auth");
    if (!stored) return null;
    return JSON.parse(stored).token;
  } catch {
    return null;
  }
}

export async function loginApi(
  email: string,
  password: string
): Promise<LoginResponse> {
  let res: Response;
  try {
    res = await fetch(`${API_URL}/api/login`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ email, password }),
    });
  } catch {
    throw new Error("Unable to reach the server. Please try again.");
  }

  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.message ?? "Login failed");
  }

  return res.json();
}

export async function registerApi(
  email: string,
  password: string
): Promise<LoginResponse> {
  let res: Response;
  try {
    res = await fetch(`${API_URL}/api/register`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ email, password, password_confirmation: password }),
    });
  } catch {
    throw new Error("Unable to reach the server. Please try again.");
  }

  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.message ?? "Registration failed");
  }

  return res.json();
}

export async function updateProfileApi(
  name: string,
  avatarId: number
): Promise<PortalUser> {
  const res = await authFetch("/api/profile", {
    method: "PATCH",
    body: JSON.stringify({ name, avatar_id: avatarId }),
  });
  return res.json();
}

export interface PortalLink {
  id: number;
  status: "active" | "revoked";
  service_connection: {
    id: number;
    account_number: string;
    meter_number: string;
    registered_name: string;
    barangay: { name: string } | null;
  };
}

export async function getLinks(): Promise<PortalLink[]> {
  const res = await authFetch("/api/links");
  return res.json();
}

export async function createLink(
  accountNumber: string,
  meterNumber: string
): Promise<PortalLink> {
  const res = await authFetch("/api/links", {
    method: "POST",
    body: JSON.stringify({ account_number: accountNumber, meter_number: meterNumber }),
  });
  return res.json();
}

export async function unlinkApi(linkId: number | string): Promise<void> {
  await authFetch(`/api/links/${linkId}`, { method: "DELETE" });
}

export async function logoutApi(): Promise<void> {
  const token = getToken();
  if (!token) return;

  await fetch(`${API_URL}/api/logout`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
  });
}

export async function getInvoices(): Promise<PortalInvoice[]> {
  const res = await authFetch("/api/invoices");
  return res.json();
}

export async function getInvoice(
  invoiceId: number | string
): Promise<PortalInvoice> {
  const res = await authFetch(`/api/invoices/${invoiceId}`);
  return res.json();
}

export interface PaymentIntentInfo {
  client_key: string;
  payment_intent_id: string;
  expiry_seconds: number;
}

export async function startPayment(invoiceId: number | string): Promise<PaymentIntentInfo> {
  const res = await authFetch(`/api/invoices/${invoiceId}/pay`, { method: "POST" });
  const body = await res.json();

  if (
    typeof body.client_key !== "string" ||
    typeof body.payment_intent_id !== "string" ||
    typeof body.expiry_seconds !== "number"
  ) {
    throw new ApiError(
      "The payment gateway returned an invalid response. Please try again.",
      res.status
    );
  }

  return body;
}

export function buildReturnUrl(invoiceId: number | string): string {
  return `${window.location.origin}/dashboard/pay?id=${invoiceId}&from=redirect`;
}

export interface IntentStatus {
  status: "paid" | "confirmed" | "failed" | "processing" | "unknown";
  invoice_id?: number;
}

/**
 * Resolves a PayMongo payment intent back to the user's invoice so the pay
 * screen can answer "did my payment go through?" on a redirect return. The
 * redirect itself carries no invoice id (PayMongo strips the query) — but per
 * the PayMongo docs it does carry `payment_intent_id`, which maps to the
 * invoice server-side. `confirmed` means PayMongo says the intent succeeded
 * but the webhook has not credited the invoice yet.
 */
export async function resolveIntentStatus(
  paymentIntentId: string
): Promise<IntentStatus> {
  const res = await authFetch("/api/payments/intent-status", {
    method: "POST",
    body: JSON.stringify({ payment_intent_id: paymentIntentId }),
  });
  return res.json();
}

const PENDING_INVOICE_KEY = "gw-pending-invoice";
const PENDING_INVOICE_TTL_MS = 60 * 60 * 1000;

export interface PendingInvoice {
  invoiceId: string;
  /** Correlation key for the server-side intent-status resolution. */
  paymentIntentId?: string;
  /** Informational: which method the redirect flow used. */
  method?: string;
  writtenAt: number;
}

function isPendingInvoice(value: unknown): value is PendingInvoice {
  return (
    value !== null &&
    typeof value === "object" &&
    typeof (value as PendingInvoice).invoiceId === "string" &&
    typeof (value as PendingInvoice).writtenAt === "number"
  );
}

/**
 * The redirect-based flows (GCash, card 3DS) hand the browser to PayMongo,
 * which can strip the query string on the way back — so the payment context
 * (invoice id + payment intent id) rides storage instead, written right
 * before the redirect and read on the return page.
 *
 * Two storages on purpose: sessionStorage covers a same-tab round trip;
 * localStorage survives the return landing in a NEW tab (the card 3DS flow
 * has been observed to do exactly that — sessionStorage is per-tab, so the
 * recovery silently failed there). The marker expires after an hour so a
 * stale value can never hijack a later visit, and it is NOT cleared on read
 * (a StrictMode remount would otherwise lose the recovery — it is safe to
 * read twice). The backend's ownership checks guard everything behind it.
 */
export function writePendingInvoice(
  invoiceId: number | string,
  opts: { paymentIntentId?: string; method?: string } = {}
): void {
  const pending: PendingInvoice = {
    invoiceId: String(invoiceId),
    writtenAt: Date.now(),
    ...(opts.paymentIntentId ? { paymentIntentId: opts.paymentIntentId } : {}),
    ...(opts.method ? { method: opts.method } : {}),
  };
  const raw = JSON.stringify(pending);

  try {
    window.sessionStorage.setItem(PENDING_INVOICE_KEY, raw);
  } catch {
    // storage unavailable — the return page falls back to the URL params
  }
  try {
    window.localStorage.setItem(PENDING_INVOICE_KEY, raw);
  } catch {
    // ignore
  }
}

export function readPendingInvoice(): PendingInvoice | null {
  // SSR/prerender guard: the static-export pass renders the pay page on the
  // server where window does not exist (regression 2026-08-07 — the unguarded
  // access threw "window is not defined" and blanked the page on first load).
  if (typeof window === "undefined") {
    return null;
  }

  const read = (storage: Storage): PendingInvoice | null => {
    try {
      const raw = storage.getItem(PENDING_INVOICE_KEY);
      if (!raw) return null;
      const parsed: unknown = JSON.parse(raw);
      if (!isPendingInvoice(parsed)) return null;
      if (Date.now() - parsed.writtenAt > PENDING_INVOICE_TTL_MS) return null;
      return parsed;
    } catch {
      return null;
    }
  };

  return read(window.sessionStorage) ?? read(window.localStorage);
}

export function clearPendingInvoice(): void {
  try {
    window.sessionStorage.removeItem(PENDING_INVOICE_KEY);
  } catch {
    // ignore
  }
  try {
    window.localStorage.removeItem(PENDING_INVOICE_KEY);
  } catch {
    // ignore
  }
}

export interface PortalPayment {
  id: number;
  invoice_number: string;
  billing_period_start: string;
  billing_period_end: string;
  amount: number;
  method: string;
  channel: string | null;
  paid_at: string;
  service_connection: {
    account_number: string;
    meter_number: string;
    registered_name: string;
    barangay: string | null;
  };
}

export async function getRecentPayments(): Promise<PortalPayment[]> {
  const res = await authFetch("/api/payments");
  return res.json();
}

export function formatPeso(amount: number | string | null | undefined): string {
  const n = Number(amount ?? 0);
  return Number.isFinite(n)
    ? `₱${n.toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      })}`
    : "—";
}

export class ApiError extends Error {
  status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = "ApiError";
    this.status = status;
  }
}

export interface SavedPaymentMethod {
  id: number;
  paymongo_payment_method_id: string;
  brand: string | null;
  last4: string;
  exp_month: number;
  exp_year: number;
  payer_name: string | null;
  created_at: string;
}

export async function getSavedPaymentMethods(): Promise<SavedPaymentMethod[]> {
  const res = await authFetch("/api/saved-payment-methods");
  const body = await res.json();
  return body.data ?? [];
}

export async function deleteSavedPaymentMethod(
  id: number | string
): Promise<void> {
  await authFetch(`/api/saved-payment-methods/${id}`, { method: "DELETE" });
}

export interface SavedPaymentIntentInfo {
  client_key: string;
  payment_intent_id: string;
  expiry_seconds: number;
  payment_method_id: string;
}

export async function payWithSaved(
  invoiceId: number | string,
  paymentMethodId: string,
  cvc: string
): Promise<SavedPaymentIntentInfo> {
  const res = await authFetch(
    `/api/invoices/${invoiceId}/pay-with-saved`,
    {
      method: "POST",
      body: JSON.stringify({ payment_method_id: paymentMethodId, cvc }),
    }
  );
  const body = await res.json();

  if (
    typeof body.client_key !== "string" ||
    typeof body.payment_intent_id !== "string" ||
    typeof body.payment_method_id !== "string"
  ) {
    throw new ApiError(
      "The payment gateway returned an invalid response. Please try again.",
      res.status
    );
  }

  return body;
}

async function resolveResponse(res: Response): Promise<Response> {
  if (res.ok) return res;

  const body = await res.json().catch(() => ({}));
  throw new ApiError(
    (body as { message?: string }).message ?? "Request failed",
    res.status
  );
}

export async function authFetch(
  endpoint: string,
  options: RequestInit = {}
): Promise<Response> {
  const token = getToken();

  if (!token) {
    throw new ApiError("Session expired. Please sign in again.", 401);
  }

  const headers: Record<string, string> = {
    Accept: "application/json",
    Authorization: `Bearer ${token}`,
    ...(options.headers as Record<string, string>),
  };

  if (options.body && typeof options.body === "string") {
    headers["Content-Type"] = "application/json";
  }

  let res: Response;
  try {
    res = await fetch(`${API_URL}${endpoint}`, {
      ...options,
      headers,
    });
  } catch {
    throw new ApiError("Unable to reach the server. Please try again.", 0);
  }

  return resolveResponse(res);
}
