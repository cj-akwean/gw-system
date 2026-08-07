import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { attachPaymentMethod, createPaymentMethod } from "@/lib/paymongo";

const QR_IMAGE = "data:image/png;base64,iVBORw0KGgo=";

function jsonResponse(body: unknown, ok = true, status = 200): Response {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

describe("createPaymentMethod", () => {
  beforeEach(() => {
    process.env.NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY = "pk_test_dummy";
  });

  afterEach(() => {
    delete process.env.NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY;
    vi.unstubAllGlobals();
  });

  it("posts the method type and expiry to PayMongo with the public key", async () => {
    const fetchSpy = vi.fn().mockResolvedValue(
      jsonResponse({ data: { id: "pm_qr_1" } })
    );
    vi.stubGlobal("fetch", fetchSpy);

    const id = await createPaymentMethod("qrph", { expiry_seconds: 600 });

    expect(id).toBe("pm_qr_1");
    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("https://api.paymongo.com/v1/payment_methods");
    expect((init.headers as Record<string, string>).Authorization).toBe(
      `Basic ${btoa("pk_test_dummy:")}`
    );
    expect(JSON.parse(String(init.body))).toEqual({
      data: { attributes: { type: "qrph", expiry_seconds: 600 } },
    });
  });

  it("throws when the public key is not configured", async () => {
    delete process.env.NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY;

    await expect(createPaymentMethod("gcash")).rejects.toThrow(
      /isn't configured/
    );
  });

  it("throws a friendly error on a PayMongo error payload", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse(
          { errors: [{ code: "parameter_invalid", detail: "expiry out of range" }] },
          false,
          400
        )
      )
    );

    await expect(createPaymentMethod("qrph")).rejects.toThrow(
      /expiry out of range \(parameter_invalid\)/
    );
  });
});

describe("attachPaymentMethod", () => {
  beforeEach(() => {
    process.env.NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY = "pk_test_dummy";
  });

  afterEach(() => {
    delete process.env.NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY;
    vi.unstubAllGlobals();
  });

  it("attaches with client key and return url, and returns the QR image", async () => {
    const fetchSpy = vi.fn().mockResolvedValue(
      jsonResponse({
        data: {
          id: "pi_1",
          attributes: {
            status: "awaiting_next_action",
            next_action: {
              type: "consume_qr",
              code: {
                image_url: QR_IMAGE,
                expires_at: "2026-08-07T13:06:02.39562891Z",
              },
            },
          },
        },
      })
    );
    vi.stubGlobal("fetch", fetchSpy);

    const result = await attachPaymentMethod({
      intentId: "pi_1",
      clientKey: "ck_1",
      paymentMethodId: "pm_qr_1",
      returnUrl: "http://localhost/dashboard/pay/1",
    });

    expect(result.imageUrl).toBe(QR_IMAGE);
    expect(result.redirectUrl).toBeNull();
    expect(result.expiresAt).toBe("2026-08-07T13:06:02.39562891Z");
    expect(result.status).toBe("awaiting_next_action");

    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("https://api.paymongo.com/v1/payment_intents/pi_1/attach");
    expect(JSON.parse(String(init.body))).toEqual({
      data: {
        attributes: {
          payment_method: "pm_qr_1",
          client_key: "ck_1",
          return_url: "http://localhost/dashboard/pay/1",
        },
      },
    });
  });

  it("accepts the legacy 'code' next_action type as a defensive alias", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse({
          data: {
            id: "pi_1",
            attributes: {
              status: "awaiting_next_action",
              next_action: {
                type: "code",
                code: { image_url: QR_IMAGE },
              },
            },
          },
        })
      )
    );

    const result = await attachPaymentMethod({
      intentId: "pi_1",
      clientKey: "ck_1",
      paymentMethodId: "pm_qr_1",
    });

    expect(result.imageUrl).toBe(QR_IMAGE);
    expect(result.expiresAt).toBeNull();
  });

  it("returns a redirect url for redirect-based methods", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse({
          data: {
            id: "pi_1",
            attributes: {
              status: "awaiting_next_action",
              next_action: {
                type: "redirect",
                redirect: { url: "https://checkout.paymongo.com/gcash/xyz" },
              },
            },
          },
        })
      )
    );

    const result = await attachPaymentMethod({
      intentId: "pi_1",
      clientKey: "ck_1",
      paymentMethodId: "pm_gcash_1",
    });

    expect(result.redirectUrl).toBe(
      "https://checkout.paymongo.com/gcash/xyz"
    );
    expect(result.imageUrl).toBeNull();
  });

  it("refuses non-data-URI image urls (XSS hygiene)", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse({
          data: {
            id: "pi_1",
            attributes: {
              status: "awaiting_next_action",
              next_action: {
                type: "consume_qr",
                code: { image_url: "javascript:alert(1)" },
              },
            },
          },
        })
      )
    );

    const result = await attachPaymentMethod({
      intentId: "pi_1",
      clientKey: "ck_1",
      paymentMethodId: "pm_qr_1",
    });

    expect(result.imageUrl).toBeNull();
  });
});
