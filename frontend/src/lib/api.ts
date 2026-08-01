const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000";

interface LoginResponse {
  token: string;
  user: { id: number; name: string; email: string };
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

export async function authFetch(
  endpoint: string,
  options: RequestInit = {}
): Promise<Response> {
  const token = getToken();
  const headers: Record<string, string> = {
    Accept: "application/json",
    ...(options.headers as Record<string, string>),
  };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  if (options.body && typeof options.body === "string") {
    headers["Content-Type"] = "application/json";
  }

  const res = await fetch(`${API_URL}${endpoint}`, {
    ...options,
    headers,
  });

  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.message ?? "Request failed");
  }

  return res;
}
