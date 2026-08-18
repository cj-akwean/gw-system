import { describe, it, expect, vi, beforeEach } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { PastPayments, paymentMethodLabel } from "./past-payments";
import type { PortalPayment } from "@/lib/api";

const mockGetRecentPayments = vi.fn();
const mockLogout = vi.fn();
const mockReplace = vi.fn();
const mockRouter = { replace: mockReplace, push: vi.fn() };

vi.mock("@/lib/api", () => ({
  getRecentPayments: (...args: unknown[]) => mockGetRecentPayments(...args),
  formatPeso: (n: number) => `₱${n.toFixed(2)}`,
  ApiError: class extends Error {
    status: number;
    constructor(message: string, status: number) {
      super(message);
      this.status = status;
    }
  },
}));

vi.mock("next/navigation", () => ({
  useRouter: () => mockRouter,
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ logout: mockLogout }),
}));

const payment = (overrides: Partial<PortalPayment> = {}): PortalPayment => ({
  id: 1,
  invoice_number: "GW-2026-00001",
  billing_period_start: "2026-07-01",
  billing_period_end: "2026-07-31",
  amount: 150,
  method: "paymongo",
  channel: "gcash",
  paid_at: "2026-08-07T12:00:00+08:00",
  service_connection: {
    account_number: "GW-00001",
    meter_number: "MTR-00001",
    registered_name: "Maria Santos",
    barangay: "Poblacion",
  },
  ...overrides,
});

describe("PastPayments", () => {
  beforeEach(() => {
    mockGetRecentPayments.mockReset();
    mockLogout.mockReset();
    mockReplace.mockReset();
  });

  it("does not fetch while collapsed", () => {
    render(<PastPayments />);

    expect(mockGetRecentPayments).not.toHaveBeenCalled();
  });

  it("fetches and lists payments when expanded", async () => {
    mockGetRecentPayments.mockResolvedValue([
      payment(),
      payment({ id: 2, invoice_number: "GW-2026-00002", amount: 250, channel: "qrph" }),
    ]);

    render(<PastPayments />);
    fireEvent.click(screen.getByTestId("past-payments-toggle"));

    expect(await screen.findByTestId("past-payments-list")).toBeInTheDocument();
    expect(mockGetRecentPayments).toHaveBeenCalledTimes(1);
    expect(screen.getByText("GW-2026-00001")).toBeInTheDocument();
    expect(screen.getByText("GW-2026-00002")).toBeInTheDocument();
    expect(screen.getByText("GCash")).toBeInTheDocument();
    expect(screen.getByText("QR Ph")).toBeInTheDocument();
  });

  it("shows an empty state", async () => {
    mockGetRecentPayments.mockResolvedValue([]);

    render(<PastPayments />);
    fireEvent.click(screen.getByTestId("past-payments-toggle"));

    expect(await screen.findByText(/no past payments yet/i)).toBeInTheDocument();
  });

  it("shows an error state and retries", async () => {
    mockGetRecentPayments
      .mockRejectedValueOnce(
        Object.assign(new Error("boom"), { status: 500, message: "boom" })
      )
      .mockResolvedValueOnce([payment()]);

    render(<PastPayments />);
    fireEvent.click(screen.getByTestId("past-payments-toggle"));

    expect(
      await screen.findByText(/couldn't load past payments/i)
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /try again/i }));

    expect(await screen.findByTestId("past-payments-list")).toBeInTheDocument();
  });

  it("redirects to /auth and logs out on a 401", async () => {
    mockGetRecentPayments.mockRejectedValue(
      Object.assign(new Error("Unauthenticated."), { status: 401 })
    );
    mockLogout.mockResolvedValue(undefined);

    render(<PastPayments />);
    fireEvent.click(screen.getByTestId("past-payments-toggle"));

    await vi.waitFor(() => {
      expect(mockLogout).toHaveBeenCalled();
      expect(mockReplace).toHaveBeenCalledWith("/auth");
    });
  });
});

describe("paymentMethodLabel", () => {
  it("maps PayMongo channels to friendly labels", () => {
    expect(paymentMethodLabel(payment({ channel: "gcash" }))).toBe("GCash");
    expect(paymentMethodLabel(payment({ channel: "qrph" }))).toBe("QR Ph");
    expect(paymentMethodLabel(payment({ channel: "card" }))).toBe("Card");
    expect(paymentMethodLabel(payment({ channel: "google_pay_card" }))).toBe(
      "Google Pay"
    );
    expect(paymentMethodLabel(payment({ channel: "googlepay" }))).toBe("Google Pay");
  });

  it("maps offline methods and falls back to PayMongo / raw method", () => {
    expect(paymentMethodLabel(payment({ method: "cash", channel: null }))).toBe(
      "Cash / office"
    );
    // Older online rows have no channel (paymongo_source added later, no
    // backfill) — label them like the admin does ("Processed By: PayMongo").
    expect(paymentMethodLabel(payment({ method: "paymongo", channel: null }))).toBe(
      "PayMongo"
    );
    expect(paymentMethodLabel(payment({ method: "bank_transfer", channel: null }))).toBe(
      "Bank Transfer"
    );
  });
});
