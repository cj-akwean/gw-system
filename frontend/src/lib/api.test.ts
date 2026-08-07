import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import {
  buildReturnUrl,
  formatPeso,
  getInvoice,
  startPayment,
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
  it("points back to the payment screen with the gcash marker", () => {
    expect(buildReturnUrl(7)).toBe(
      `${window.location.origin}/dashboard/pay?id=7&from=gcash`
    );
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