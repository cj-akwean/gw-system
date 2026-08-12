import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import {
  buildReturnUrl,
  changePasswordApi,
  clearPendingInvoice,
  createLink,
  formatPeso,
  getInvoice,
  getLinks,
  readPendingInvoice,
  registerApi,
  resetPasswordApi,
  resolveIntentStatus,
  sendPasswordChangeOtp,
  sendPasswordResetOtp,
  startPayment,
  unlinkApi,
  updateProfileApi,
  writePendingInvoice,
} from "@/lib/api";

function jsonResponse(body: unknown, ok = true, status = 200): Response {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

function seedAuthToken(): void {
  localStorage.setItem("auth", JSON.stringify({ token: "token-1", user: {} }));
}

describe("startPayment", () => {
  beforeEach(() => {
    seedAuthToken();
  });

  afterEach(() => {
    localStorage.removeItem("auth");
    vi.unstubAllGlobals();
  });

  it("posts to the pay endpoint and returns the intent info", async () => {
    const fetchSpy = vi.fn().mockResolvedValue(
      jsonResponse({
        client_key: "ck_1",
        payment_intent_id: "pi_1",
        expiry_seconds: 600,
      })
    );
    vi.stubGlobal("fetch", fetchSpy);

    const info = await startPayment(3);

    expect(info).toEqual({
      client_key: "ck_1",
      payment_intent_id: "pi_1",
      expiry_seconds: 600,
    });
    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/invoices/3/pay");
    expect(init.method).toBe("POST");
    expect((init.headers as Record<string, string>).Authorization).toBe(
      "Bearer token-1"
    );
  });

  it("throws ApiError when the response is malformed", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(jsonResponse({ client_key: "ck_1" }))
    );

    await expect(startPayment(3)).rejects.toMatchObject({
      name: "ApiError",
      status: 200,
    });
  });

  it("propagates the API error message", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse({ message: "Invoice is already paid." }, false, 409)
      )
    );

    await expect(startPayment(3)).rejects.toMatchObject({
      message: "Invoice is already paid.",
      status: 409,
    });
  });
});

describe("getInvoice", () => {
  beforeEach(() => {
    seedAuthToken();
  });

  afterEach(() => {
    localStorage.removeItem("auth");
    vi.unstubAllGlobals();
  });

  it("fetches a single invoice with its status", async () => {
    const fetchSpy = vi.fn().mockResolvedValue(
      jsonResponse({
        id: 11,
        invoice_number: "GW-2026-00011",
        status: "paid",
        total_amount: 438.6,
        service_connection: {
          account_number: "GW-00001",
          meter_number: "MTR-00001",
          registered_name: "Melissa Will",
          barangay: null,
        },
      })
    );
    vi.stubGlobal("fetch", fetchSpy);

    const invoice = await getInvoice(11);

    expect(invoice.status).toBe("paid");
    expect(invoice.total_amount).toBe(438.6);
    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/invoices/11");
    expect((init.headers as Record<string, string>).Authorization).toBe(
      "Bearer token-1"
    );
  });

  it("throws ApiError on a 403", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(jsonResponse({ message: "Forbidden" }, false, 403))
    );

    await expect(getInvoice(99)).rejects.toMatchObject({
      message: "Forbidden",
      status: 403,
    });
  });
});

describe("buildReturnUrl", () => {
  it("points back to the payment screen with the redirect marker", () => {
    expect(buildReturnUrl(7)).toBe(
      `${window.location.origin}/dashboard/pay?id=7&from=redirect`
    );
  });
});

describe("resolveIntentStatus", () => {
  beforeEach(() => {
    seedAuthToken();
  });

  afterEach(() => {
    localStorage.removeItem("auth");
    vi.unstubAllGlobals();
  });

  it("posts the intent id to the intent-status endpoint", async () => {
    const fetchSpy = vi.fn().mockResolvedValue(
      jsonResponse({ status: "confirmed", invoice_id: 12 })
    );
    vi.stubGlobal("fetch", fetchSpy);

    const result = await resolveIntentStatus("pi_abc123");

    expect(result).toEqual({ status: "confirmed", invoice_id: 12 });
    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/payments/intent-status");
    expect(init.method).toBe("POST");
    expect((init.headers as Record<string, string>).Authorization).toBe(
      "Bearer token-1"
    );
    expect(JSON.parse(init.body as string)).toEqual({
      payment_intent_id: "pi_abc123",
    });
  });

  it("propagates a 403 from the server", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(jsonResponse({ message: "Forbidden" }, false, 403))
    );

    await expect(resolveIntentStatus("pi_foreign")).rejects.toMatchObject({
      message: "Forbidden",
      status: 403,
    });
  });
});

describe("pending invoice (session + localStorage round trip)", () => {
  beforeEach(() => {
    window.sessionStorage.clear();
    window.localStorage.clear();
  });

  it("writes, reads, and clears the pending record", () => {
    expect(readPendingInvoice()).toBeNull();

    writePendingInvoice(12);
    expect(readPendingInvoice()).toMatchObject({ invoiceId: "12" });

    clearPendingInvoice();
    expect(readPendingInvoice()).toBeNull();
    expect(window.sessionStorage.getItem("gw-pending-invoice")).toBeNull();
    expect(window.localStorage.getItem("gw-pending-invoice")).toBeNull();
  });

  it("carries the payment intent id and method for server-side resolution", () => {
    writePendingInvoice(12, {
      paymentIntentId: "pi_abc123",
      method: "card",
    });

    expect(readPendingInvoice()).toEqual({
      invoiceId: "12",
      paymentIntentId: "pi_abc123",
      method: "card",
      writtenAt: expect.any(Number),
    });
  });

  it("reads the localStorage copy when sessionStorage was lost (new-tab return)", () => {
    window.localStorage.setItem(
      "gw-pending-invoice",
      JSON.stringify({
        invoiceId: "12",
        paymentIntentId: "pi_abc123",
        writtenAt: Date.now(),
      })
    );

    expect(readPendingInvoice()).toMatchObject({
      invoiceId: "12",
      paymentIntentId: "pi_abc123",
    });
  });

  it("ignores an expired marker", () => {
    window.localStorage.setItem(
      "gw-pending-invoice",
      JSON.stringify({
        invoiceId: "12",
        writtenAt: Date.now() - 61 * 60 * 1000,
      })
    );

    expect(readPendingInvoice()).toBeNull();
  });

  it("ignores a malformed marker", () => {
    window.localStorage.setItem("gw-pending-invoice", "12");

    expect(readPendingInvoice()).toBeNull();
  });
});

describe("registerApi", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("posts email + password and returns the session", async () => {
    const fetchSpy = vi.fn().mockResolvedValue(
      jsonResponse(
        {
          token: "tok-register",
          user: { id: 9, name: null, email: "new@example.com", avatar_id: null },
        },
        true,
        201
      )
    );
    vi.stubGlobal("fetch", fetchSpy);

    const data = await registerApi("new@example.com", "secret123");

    expect(data.token).toBe("tok-register");
    expect(data.user).toMatchObject({ email: "new@example.com", avatar_id: null });
    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/register");
    expect(JSON.parse(String(init.body))).toEqual({
      email: "new@example.com",
      password: "secret123",
      password_confirmation: "secret123",
    });
  });

  it("surfaces the duplicate-email message", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse(
          { message: "An account with this email already exists." },
          false,
          422
        )
      )
    );

    await expect(registerApi("taken@example.com", "secret123")).rejects.toThrow(
      "An account with this email already exists."
    );
  });
});

describe("updateProfileApi", () => {
  beforeEach(() => {
    seedAuthToken();
  });

  afterEach(() => {
    localStorage.removeItem("auth");
    vi.unstubAllGlobals();
  });

  it("patches name and avatar_id", async () => {
    const fetchSpy = vi.fn().mockResolvedValue(
      jsonResponse({
        id: 1,
        name: "AquaFan",
        email: "fan@example.com",
        avatar_id: 3,
      })
    );
    vi.stubGlobal("fetch", fetchSpy);

    const user = await updateProfileApi("AquaFan", 3);

    expect(user).toMatchObject({ name: "AquaFan", avatar_id: 3 });
    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/profile");
    expect(init.method).toBe("PATCH");
    expect(JSON.parse(String(init.body))).toEqual({ name: "AquaFan", avatar_id: 3 });
  });
});

describe("changePasswordApi", () => {
  beforeEach(() => {
    seedAuthToken();
  });

  afterEach(() => {
    localStorage.removeItem("auth");
    vi.unstubAllGlobals();
  });

  it("posts current, new password and otp to the password endpoint", async () => {
    const fetchSpy = vi
      .fn()
      .mockResolvedValue(jsonResponse({ message: "Password updated." }));
    vi.stubGlobal("fetch", fetchSpy);

    await changePasswordApi("old-password-1", "new-password-1", "123456");

    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/password");
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({
      current_password: "old-password-1",
      password: "new-password-1",
      password_confirmation: "new-password-1",
      otp: "123456",
    });
    expect((init.headers as Record<string, string>).Authorization).toBe(
      "Bearer token-1"
    );
  });

  it("throws ApiError with the server message on failure", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse(
          { message: "The current password is incorrect." },
          false,
          422
        )
      )
    );

    await expect(
      changePasswordApi("wrong", "new-password-1", "123456")
    ).rejects.toMatchObject({
      name: "ApiError",
      message: "The current password is incorrect.",
      status: 422,
    });
  });

  it("posts to the send-code endpoint", async () => {
    const fetchSpy = vi
      .fn()
      .mockResolvedValue(jsonResponse({ message: "Verification code sent to your email." }));
    vi.stubGlobal("fetch", fetchSpy);

    await sendPasswordChangeOtp();

    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/password/send-code");
    expect(init.method).toBe("POST");
    expect((init.headers as Record<string, string>).Authorization).toBe(
      "Bearer token-1"
    );
  });
});

describe("forgot / reset password api", () => {
  beforeEach(() => {
    seedAuthToken();
  });

  afterEach(() => {
    localStorage.removeItem("auth");
    vi.unstubAllGlobals();
  });

  it("posts the email to the forgot-password endpoint", async () => {
    const fetchSpy = vi
      .fn()
      .mockResolvedValue(
        jsonResponse({ message: "If an account exists for that email, a verification code is on its way." })
      );
    vi.stubGlobal("fetch", fetchSpy);

    await sendPasswordResetOtp("lost@example.com");

    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/forgot-password");
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({ email: "lost@example.com" });
  });

  it("throws ApiError with the server message when forgot-password fails", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(jsonResponse({ message: "Couldn't send the code." }, false, 422))
    );

    await expect(sendPasswordResetOtp("lost@example.com")).rejects.toMatchObject({
      name: "ApiError",
      message: "Couldn't send the code.",
      status: 422,
    });
  });

  it("posts email, otp and password to the reset endpoint", async () => {
    const fetchSpy = vi
      .fn()
      .mockResolvedValue(jsonResponse({ message: "Password reset. You can now sign in." }));
    vi.stubGlobal("fetch", fetchSpy);

    await resetPasswordApi("lost@example.com", "123456", "new-password-1");

    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/reset-password");
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({
      email: "lost@example.com",
      otp: "123456",
      password: "new-password-1",
      password_confirmation: "new-password-1",
    });
  });

  it("throws ApiError with the server message when reset fails", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse({ message: "That code is invalid or has expired." }, false, 422)
      )
    );

    await expect(
      resetPasswordApi("lost@example.com", "000000", "new-password-1")
    ).rejects.toMatchObject({
      name: "ApiError",
      message: "That code is invalid or has expired.",
      status: 422,
    });
  });
});

describe("links api", () => {
  beforeEach(() => {
    seedAuthToken();
  });

  afterEach(() => {
    localStorage.removeItem("auth");
    vi.unstubAllGlobals();
  });

  it("creates a link with account + meter number", async () => {
    const fetchSpy = vi.fn().mockResolvedValue(
      jsonResponse(
        {
          id: 5,
          status: "active",
          service_connection: {
            id: 2,
            account_number: "GW-0001",
            meter_number: "MTR-0001",
            registered_name: "Maria Santos",
            barangay: null,
          },
        },
        true,
        201
      )
    );
    vi.stubGlobal("fetch", fetchSpy);

    const link = await createLink("GW-0001", "MTR-0001");

    expect(link.service_connection.account_number).toBe("GW-0001");
    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/links");
    expect(JSON.parse(String(init.body))).toEqual({
      account_number: "GW-0001",
      meter_number: "MTR-0001",
    });
  });

  it("surfaces the already-linked 409 message", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse(
          { message: "This meter is already linked to another account." },
          false,
          409
        )
      )
    );

    await expect(createLink("GW-0001", "MTR-0001")).rejects.toMatchObject({
      name: "ApiError",
      status: 409,
      message: "This meter is already linked to another account.",
    });
  });

  it("lists the user's active links", async () => {
    const fetchSpy = vi.fn().mockResolvedValue(
      jsonResponse([
        {
          id: 5,
          status: "active",
          service_connection: {
            id: 2,
            account_number: "GW-0001",
            meter_number: "MTR-0001",
            registered_name: "Maria Santos",
            barangay: null,
          },
        },
      ])
    );
    vi.stubGlobal("fetch", fetchSpy);

    const links = await getLinks();

    expect(links).toHaveLength(1);
    expect(links[0].status).toBe("active");
    const [url] = fetchSpy.mock.calls[0] as [string];
    expect(url).toBe("http://127.0.0.1:8000/api/links");
  });

  it("unlinks (revokes) a meter link", async () => {
    const fetchSpy = vi.fn().mockResolvedValue(
      jsonResponse({ message: "Link revoked" })
    );
    vi.stubGlobal("fetch", fetchSpy);

    await unlinkApi(5);

    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/links/5");
    expect(init?.method).toBe("DELETE");
  });
});

describe("formatPeso", () => {
  it("formats amounts with two decimals", () => {
    expect(formatPeso(150)).toBe("₱150.00");
    expect(formatPeso(2054.5)).toBe("₱2,054.50");
  });

  it("handles zero and null", () => {
    expect(formatPeso(0)).toBe("₱0.00");
    expect(formatPeso(null)).toBe("₱0.00");
    expect(formatPeso(undefined)).toBe("₱0.00");
  });

  it("handles string input", () => {
    expect(formatPeso("205.00")).toBe("₱205.00");
  });

  it("returns an em dash for non-finite input", () => {
    expect(formatPeso(NaN)).toBe("—");
    expect(formatPeso("abc")).toBe("—");
  });
});