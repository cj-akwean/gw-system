const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000";

interface LoginResponse {
  token: string;
  user: { id: number; name: string; email: string };
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
  status: "unpaid" | "overdue";
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
