import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import PayPage from "@/app/dashboard/pay/page";

const mockReplace = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: mockReplace, push: vi.fn() }),
  useSearchParams: () => mockSearchParams(),
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => mockUseAuth(),
}));

vi.mock("@/components/portal/payment-method", () => ({
  PaymentMethodScreen: ({
    invoiceId,
    paymentIntentId,
    returnedFromRedirect,
  }: {
    invoiceId: string;
    paymentIntentId?: string | null;
    returnedFromRedirect?: boolean;
  }) => (
    <div>
      pay-screen {invoiceId} {paymentIntentId ? `intent-${paymentIntentId}` : ""}{" "}
      {returnedFromRedirect ? "redirect-return" : ""}
    </div>
  ),
}));

let mockUseAuth: () => {
  user: { id: number; name: string; email: string } | null;
  isAuthenticated: boolean;
  ready: boolean;
  logout: () => void;
};

let mockSearchParams: () => URLSearchParams;

const USER = { id: 1, name: "Maria", email: "maria@example.com" };

function pendingMarker(
  invoiceId: string,
  ageMs = 0,
  paymentIntentId?: string
): void {
  const raw = JSON.stringify({
    invoiceId,
    ...(paymentIntentId ? { paymentIntentId } : {}),
    writtenAt: Date.now() - ageMs,
  });
  window.sessionStorage.setItem("gw-pending-invoice", raw);
  window.localStorage.setItem("gw-pending-invoice", raw);
}

describe("PayPage guard", () => {
  beforeEach(() => {
    mockReplace.mockReset();
    window.sessionStorage.clear();
    window.localStorage.clear();
    mockSearchParams = () => new URLSearchParams("id=7");
    mockUseAuth = () => ({
      user: USER,
      isAuthenticated: true,
      ready: true,
      logout: vi.fn(),
    });
  });

  it("renders nothing until auth state is ready", () => {
    mockUseAuth = () => ({
      user: null,
      isAuthenticated: true,
      ready: false,
      logout: vi.fn(),
    });

    render(<PayPage />);

    expect(screen.queryByText(/pay-screen/)).not.toBeInTheDocument();
  });

  it("redirects to /auth when not authenticated", () => {
    mockUseAuth = () => ({
      user: null,
      isAuthenticated: false,
      ready: true,
      logout: vi.fn(),
    });

    render(<PayPage />);

    expect(mockReplace).toHaveBeenCalledWith("/auth");
  });

  it("renders the payment screen for the invoice in the router search params", async () => {
    render(<PayPage />);

    expect(await screen.findByText(/pay-screen 7/)).toBeInTheDocument();
  });

  it("passes the payment intent id from PayMongo's return query", async () => {
    mockSearchParams = () =>
      new URLSearchParams("payment_intent_id=pi_abc123&status=succeeded");

    render(<PayPage />);

    expect(await screen.findByText(/intent-pi_abc123/)).toBeInTheDocument();
    expect(screen.getByText(/redirect-return/)).toBeInTheDocument();
  });

  it("flags a return with only PayMongo's status param", async () => {
    mockSearchParams = () => new URLSearchParams("status=succeeded");

    render(<PayPage />);

    expect(await screen.findByText(/redirect-return/)).toBeInTheDocument();
  });

  it("passes an empty id when the query string is missing and no marker exists", async () => {
    mockSearchParams = () => new URLSearchParams("");

    render(<PayPage />);

    expect(await screen.findByText("pay-screen")).toBeInTheDocument();
    expect(screen.queryByText(/redirect-return/)).not.toBeInTheDocument();
  });

  it("recovers the invoice from sessionStorage on a bare redirect return", async () => {
    pendingMarker("7");
    mockSearchParams = () => new URLSearchParams("");

    render(<PayPage />);

    expect(
      await screen.findByText(/pay-screen 7 redirect-return/)
    ).toBeInTheDocument();
  });

  it("recovers the payment intent id from storage when the URL has none", async () => {
    pendingMarker("7", 0, "pi_stored_1");
    mockSearchParams = () => new URLSearchParams("");

    render(<PayPage />);

    expect(await screen.findByText(/intent-pi_stored_1/)).toBeInTheDocument();
    expect(
      screen.getByText(/pay-screen 7 intent-pi_stored_1 redirect-return/)
    ).toBeInTheDocument();
  });

  it("recovers the invoice from localStorage when the return landed in a new tab", async () => {
    // A new tab has fresh sessionStorage — only the localStorage copy exists.
    window.localStorage.setItem(
      "gw-pending-invoice",
      JSON.stringify({ invoiceId: "9", writtenAt: Date.now() })
    );
    mockSearchParams = () => new URLSearchParams("");

    render(<PayPage />);

    expect(
      await screen.findByText(/pay-screen 9 redirect-return/)
    ).toBeInTheDocument();
  });

  it("ignores an expired pending marker", async () => {
    pendingMarker("7", 61 * 60 * 1000);
    mockSearchParams = () => new URLSearchParams("");

    render(<PayPage />);

    expect(await screen.findByText("pay-screen")).toBeInTheDocument();
    expect(screen.queryByText(/redirect-return/)).not.toBeInTheDocument();
  });

  it("prefers the url id over a stale pending invoice", async () => {
    pendingMarker("3");
    mockSearchParams = () => new URLSearchParams("id=9");

    render(<PayPage />);

    expect(await screen.findByText(/pay-screen 9/)).toBeInTheDocument();
  });

  it("recovers the pending intent id when the url has an id but no intent (frictionless refresh)", async () => {
    // A frictionless card refresh keeps ?id=9 but the intent id only lives in
    // the pending record — it must be recovered per-field.
    pendingMarker("9", 0, "pi_stored_2");
    mockSearchParams = () => new URLSearchParams("id=9");

    render(<PayPage />);

    expect(await screen.findByText(/pay-screen 9 intent-pi_stored_2/)).toBeInTheDocument();
    expect(screen.getByText(/redirect-return/)).toBeInTheDocument();
  });

  it("flags a redirect return (3DS / GCash)", async () => {
    mockSearchParams = () => new URLSearchParams("id=7&from=redirect");

    render(<PayPage />);

    expect(
      await screen.findByText("pay-screen 7 redirect-return")
    ).toBeInTheDocument();
  });

  it("accepts the legacy gcash return marker", async () => {
    mockSearchParams = () => new URLSearchParams("id=7&from=gcash");

    render(<PayPage />);

    expect(
      await screen.findByText("pay-screen 7 redirect-return")
    ).toBeInTheDocument();
  });
});
